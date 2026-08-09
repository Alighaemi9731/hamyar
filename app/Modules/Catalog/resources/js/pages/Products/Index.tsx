import { Head, Link, router } from '@inertiajs/react';
import { PackageIcon, PlusIcon, TagsIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { AppShell } from '@/layouts/app-shell';

interface ProductRow {
  id: number;
  name: string;
  sku: string | null;
  type: string;
  type_label: string;
  brand: string | null;
  category: string | null;
  variant_count: number;
  is_active: boolean;
}

interface Filters {
  q: string;
  category_id: number | null;
  brand_id: number | null;
  type: string | null;
  include_inactive: boolean;
}

interface Props {
  products: { rows: ProductRow[]; links: PaginationLink[]; total: number };
  filters: Filters;
  categories: { id: number; label: string }[];
  brands: { id: number; label: string }[];
  types: { value: string; label: string }[];
}

const ALL = 'all';

/**
 * The catalogue list.
 *
 * Filtering happens on the server, because a shop with four thousand accessory lines
 * cannot ship them all to the browser to filter client-side. The text box is debounced
 * and every visit replaces history rather than stacking it, so Back leaves the screen
 * instead of walking through every keystroke.
 */
export default function ProductsIndex({ products, filters, categories, brands, types }: Props) {
  const [term, setTerm] = useState(filters.q);
  const first = useRef(true);

  useEffect(() => {
    if (first.current) {
      first.current = false;

      return;
    }

    const timer = window.setTimeout(() => visit({ q: term }), 300);

    return () => window.clearTimeout(timer);
    // Only the typed term drives this effect; `visit` is stable enough and adding it
    // would re-fire the search on every render.
  }, [term]);

  function visit(changes: Record<string, string | number | boolean | null>): void {
    router.get(
      '/catalog',
      {
        q: term,
        category_id: filters.category_id,
        brand_id: filters.brand_id,
        type: filters.type,
        include_inactive: filters.include_inactive,
        ...changes,
      },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  const columns: Column<ProductRow>[] = [
    {
      key: 'name',
      header: 'کالا',
      cell: (row) => (
        <span className="flex min-w-0 flex-col">
          <Link href={`/catalog/products/${row.id}`} className="truncate font-medium text-primary">
            {row.name}
          </Link>
          {row.sku && (
            <span className="text-2xs text-muted-foreground">
              <Num value={row.sku} variant="ltr" />
            </span>
          )}
        </span>
      ),
    },
    {
      key: 'brand',
      header: 'برند',
      secondary: true,
      cell: (row) => row.brand ?? <span className="text-muted-foreground">—</span>,
    },
    {
      key: 'category',
      header: 'دسته',
      secondary: true,
      cell: (row) => row.category ?? <span className="text-muted-foreground">بدون دسته</span>,
    },
    {
      key: 'type',
      header: 'نوع',
      cell: (row) => (
        <Badge variant="outline" className="rounded-full font-normal">
          {row.type_label}
        </Badge>
      ),
    },
    {
      key: 'variants',
      header: 'تنوع',
      numeric: true,
      cell: (row) => <Num value={row.variant_count} variant="table" />,
    },
    {
      key: 'active',
      header: 'وضعیت',
      cell: (row) =>
        row.is_active ? (
          <span className="text-2xs text-success">فعال</span>
        ) : (
          <span className="text-2xs text-muted-foreground">غیرفعال</span>
        ),
    },
  ];

  return (
    <AppShell
      title="کالاها"
      actions={
        <>
          <Button variant="outline" asChild>
            <Link href="/catalog/categories">
              <TagsIcon className="size-4" />
              دسته‌بندی
            </Link>
          </Button>
          <Button variant="outline" asChild>
            <Link href="/catalog/prices">قیمت‌ها</Link>
          </Button>
          <Button asChild>
            <Link href="/catalog/products/create">
              <PlusIcon className="size-4" />
              کالای جدید
            </Link>
          </Button>
        </>
      }
    >
      <Head title="کالاها" />

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <FilterSelect
          label="دسته"
          value={filters.category_id}
          options={categories.map((c) => ({ value: String(c.id), label: c.label }))}
          allLabel="همه دسته‌ها"
          onChange={(value) => visit({ category_id: value })}
        />
        <FilterSelect
          label="برند"
          value={filters.brand_id}
          options={brands.map((b) => ({ value: String(b.id), label: b.label }))}
          allLabel="همه برندها"
          onChange={(value) => visit({ brand_id: value })}
        />
        <FilterSelect
          label="نوع"
          value={filters.type}
          options={types.map((t) => ({ value: t.value, label: t.label }))}
          allLabel="عادی و سریال‌دار"
          onChange={(value) => visit({ type: value })}
        />
        <FilterSelect
          label="کالاهای غیرفعال"
          value={filters.include_inactive ? '1' : null}
          options={[{ value: '1', label: 'نمایش داده شوند' }]}
          allLabel="پنهان باشند"
          onChange={(value) => visit({ include_inactive: value === '1' })}
        />
      </div>

      <DataTable
        columns={columns}
        rows={products.rows}
        rowKey={(row) => row.id}
        caption="فهرست کالاهای فروشگاه"
        search={{ value: term, onChange: setTerm, placeholder: 'نام کالا یا کد…' }}
        onRowClick={(row) => router.visit(`/catalog/products/${row.id}`)}
        empty={
          <EmptyState
            icon={PackageIcon}
            title="هنوز کالایی ثبت نشده"
            description="با یک مدل گوشی شروع کنید؛ رنگ و حافظه را بعداً به‌صورت ماتریس می‌سازید."
            action={
              <Button asChild>
                <Link href="/catalog/products/create">ثبت اولین کالا</Link>
              </Button>
            }
          />
        }
      />

      <Pagination className="mt-6" links={products.links} total={products.total} unit="کالا" />
    </AppShell>
  );
}

function FilterSelect({
  label,
  value,
  options,
  allLabel,
  onChange,
}: {
  label: string;
  value: string | number | null;
  options: { value: string; label: string }[];
  allLabel: string;
  onChange: (value: string | null) => void;
}) {
  return (
    <label className="space-y-1.5">
      <span className="text-2xs text-muted-foreground">{label}</span>
      <Select
        value={value === null ? ALL : String(value)}
        // The sentinel exists because a Radix Select item may not carry an empty
        // value; it becomes a real null before it reaches the query string.
        onValueChange={(next) => onChange(next === ALL ? null : next)}
      >
        <SelectTrigger className="w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent dir="rtl">
          <SelectItem value={ALL}>{allLabel}</SelectItem>
          {options.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </label>
  );
}
