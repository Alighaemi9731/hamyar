import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangleIcon, BoxesIcon, SmartphoneIcon, WalletIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { StatCard } from '@/components/domain/stat-card';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { AppShell } from '@/layouts/app-shell';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

export interface StockRow {
  variant_id: number;
  product_id: number;
  product_name: string;
  variant_name: string;
  type: string;
  barcode: string | null;
  sku: string | null;
  on_hand: number;
  threshold: number | null;
}

interface Props {
  rows: { items: StockRow[]; links: PaginationLink[]; total: number };
  summary: {
    units_on_hand: number;
    stock_value: MoneyValue | null;
    low_stock_count: number;
  };
  filters: { q: string; warehouse_id: number | null };
  warehouses: { id: number; label: string }[];
}

const ALL = 'all';

/**
 * What the shop is holding.
 *
 * The quantity column reads from two different ledgers depending on the product type —
 * a SUM of movements for accessories, a COUNT of devices for phones — and the server
 * picks the right one. Nothing here is a stored total (golden rule 3), which is why
 * this page and the shelf can be reconciled line by line.
 */
export default function StockIndex({ rows, summary, filters, warehouses }: Props) {
  const [term, setTerm] = useState(filters.q);
  const first = useRef(true);

  useEffect(() => {
    if (first.current) {
      first.current = false;

      return;
    }

    const timer = window.setTimeout(() => visit({ q: term }), 300);

    return () => window.clearTimeout(timer);
  }, [term]);

  function visit(changes: Record<string, string | number | null>): void {
    router.get(
      '/inventory',
      { ...filters, q: term, ...changes },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  const columns: Column<StockRow>[] = [
    {
      key: 'product',
      header: 'کالا',
      cell: (row) => (
        <span className="flex min-w-0 flex-col">
          <Link
            href={`/catalog/products/${row.product_id}`}
            className="truncate font-medium text-primary"
          >
            {row.product_name}
          </Link>
          <span className="truncate text-2xs text-muted-foreground">{row.variant_name}</span>
        </span>
      ),
    },
    {
      key: 'barcode',
      header: 'بارکد',
      secondary: true,
      cell: (row) =>
        row.barcode ? (
          <Num value={row.barcode} variant="ltr" />
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: 'type',
      header: 'نوع',
      secondary: true,
      cell: (row) => (
        <span className="text-2xs text-muted-foreground">
          {row.type === 'serialized' ? 'سریال‌دار' : 'عادی'}
        </span>
      ),
    },
    {
      key: 'on_hand',
      header: 'موجودی',
      numeric: true,
      cell: (row) => (
        <span
          className={cn(
            'font-medium',
            row.on_hand <= 0 && 'text-danger',
            row.threshold !== null && row.on_hand > 0 && row.on_hand <= row.threshold && 'text-warning'
          )}
        >
          <Num value={row.on_hand} variant="table" />
        </span>
      ),
    },
    {
      key: 'threshold',
      header: 'حد هشدار',
      numeric: true,
      secondary: true,
      cell: (row) =>
        row.threshold === null ? (
          <span className="text-muted-foreground">—</span>
        ) : (
          <Num value={row.threshold} variant="table" />
        ),
    },
  ];

  return (
    <AppShell
      title="انبار"
      actions={
        <Button variant="outline" asChild>
          <Link href="/inventory/units">
            <SmartphoneIcon className="size-4" />
            دستگاه‌های سریال‌دار
          </Link>
        </Button>
      }
    >
      <Head title="انبار" />

      <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <StatCard
          label="دستگاه موجود"
          value={summary.units_on_hand}
          icon={SmartphoneIcon}
          hint="شامل رزروشده و در تعمیر"
        />

        {summary.stock_value && (
          <StatCard
            label="ارزش دستگاه‌های موجود"
            value={summary.stock_value.value}
            isMoney
            icon={WalletIcon}
            hint="به بهای خرید همان دستگاه‌ها"
          />
        )}

        <StatCard
          label="اقلام زیر حد هشدار"
          value={summary.low_stock_count}
          icon={AlertTriangleIcon}
          tone={summary.low_stock_count > 0 ? 'warning' : 'neutral'}
          hint={summary.low_stock_count > 0 ? 'نیاز به سفارش دارد' : 'همه چیز بالای حد است'}
        />
      </div>

      <div className="mb-6 grid gap-3 sm:max-w-xs">
        <label className="space-y-1.5">
          <span className="text-2xs text-muted-foreground">انبار</span>
          <Select
            value={filters.warehouse_id === null ? ALL : String(filters.warehouse_id)}
            onValueChange={(value) => visit({ warehouse_id: value === ALL ? null : value })}
          >
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent dir="rtl">
              <SelectItem value={ALL}>همه انبارها</SelectItem>
              {warehouses.map((warehouse) => (
                <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                  {warehouse.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </label>
      </div>

      {summary.low_stock_count > 0 && (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-card border border-warning/25 bg-warning/10 px-5 py-4">
          <p className="text-sm text-warning">
            <Num value={summary.low_stock_count} /> قلم به حد هشدار رسیده یا تمام شده است.
          </p>
          <Button variant="outline" size="sm" asChild>
            <Link href="/inventory/low-stock">دیدن فهرست</Link>
          </Button>
        </div>
      )}

      <DataTable
        columns={columns}
        rows={rows.items}
        rowKey={(row) => row.variant_id}
        caption="موجودی کالاهای فروشگاه"
        search={{ value: term, onChange: setTerm, placeholder: 'نام کالا…' }}
        empty={
          <EmptyState
            icon={BoxesIcon}
            title="چیزی در انبار نیست"
            description="با ثبت فاکتور خرید، موجودی همین‌جا نمایش داده می‌شود."
            action={
              <Button variant="outline" asChild>
                <Link href="/catalog">رفتن به کالاها</Link>
              </Button>
            }
          />
        }
      />

      <Pagination className="mt-6" links={rows.links} total={rows.total} unit="قلم" />
    </AppShell>
  );
}
