import { Head, Link, router } from '@inertiajs/react';
import { PackageIcon, PlusIcon, PrinterIcon, TagsIcon, UploadIcon } from 'lucide-react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { FilterBar, withoutEmpty } from '@/components/domain/filter-bar';
import { FilterSelect } from '@/components/domain/filter-select';
import { Num } from '@/components/domain/num';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

/**
 * The catalogue list.
 *
 * Filtering happens on the server, because a shop with four thousand accessory lines
 * cannot ship them all to the browser to filter client-side. The search is debounced by
 * `FilterBar` and every visit replaces history rather than stacking it, so Back leaves
 * the screen instead of walking through every keystroke.
 */
export default function ProductsIndex({ products, filters, categories, brands, types }: Props) {
  function visit(changes: Record<string, string | null>): void {
    router.get('/catalog', withoutEmpty({ ...filters, ...changes }), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  }

  const filtered =
    filters.q !== '' ||
    filters.category_id !== null ||
    filters.brand_id !== null ||
    filters.type !== null ||
    filters.include_inactive;

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
          <Button variant="outline" asChild>
            <Link href="/catalog/labels">
              <PrinterIcon className="size-4" />
              چاپ برچسب
            </Link>
          </Button>
          <Button variant="outline" asChild>
            <Link href="/catalog/import">
              <UploadIcon className="size-4" />
              ورود گروهی
            </Link>
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

      {/* Type and the inactive toggle are a few states each, so they are chips; the
          category tree and the brand list are open-ended, so they are selects. */}
      <FilterBar
        className="mb-4"
        search={{ value: filters.q, label: 'جستجوی کالا', placeholder: 'نام کالا یا کد…' }}
        groups={[
          {
            key: 'type',
            label: 'نوع',
            value: filters.type,
            options: types,
            allLabel: 'عادی و سریال‌دار',
          },
          {
            key: 'include_inactive',
            label: 'کالاهای غیرفعال',
            value: filters.include_inactive ? '1' : null,
            allLabel: 'فقط فعال‌ها',
            options: [{ value: '1', label: 'با غیرفعال‌ها' }],
          },
        ]}
        onChange={visit}
        resultCount={products.total}
        resultUnit="کالا"
      >
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
      </FilterBar>

      <DataTable
        columns={columns}
        rows={products.rows}
        rowKey={(row) => row.id}
        caption="فهرست کالاهای فروشگاه"
        onRowClick={(row) => router.visit(`/catalog/products/${row.id}`)}
        empty={
          filtered ? (
            <EmptyState
              variant="search"
              title="کالایی با این فیلتر نیست"
              description="جستجو یا فیلتر را تغییر دهید."
            />
          ) : (
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
          )
        }
      />

      <Pagination className="mt-6" links={products.links} total={products.total} unit="کالا" />
    </AppShell>
  );
}
