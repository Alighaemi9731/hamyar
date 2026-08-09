import { Head, Link, router } from '@inertiajs/react';
import { ArrowRightIcon, PackageCheckIcon } from 'lucide-react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { AppShell } from '@/layouts/app-shell';
import type { StockRow } from './Index';

interface LowStockRow extends StockRow {
  threshold: number;
  is_out: boolean;
}

interface Props {
  rows: LowStockRow[];
  filters: { q: string; warehouse_id: number | null };
  warehouses: { id: number; label: string }[];
}

const ALL = 'all';

/**
 * What is about to run out.
 *
 * Only products whose owner set a threshold appear here — the list is opt-in by
 * design, because a shop wants to be told about the two chargers and emphatically not
 * about the two flagship handsets, which is normal stock for those.
 *
 * "Out" and "low" are separated rather than shaded on a gradient. They are different
 * conversations: one costs a sale today, the other is a purchase order this week.
 */
export default function LowStock({ rows, filters, warehouses }: Props) {
  const out = rows.filter((row) => row.is_out);
  const low = rows.filter((row) => !row.is_out);

  const columns: Column<LowStockRow>[] = [
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
      key: 'on_hand',
      header: 'موجودی',
      numeric: true,
      cell: (row) => (
        <span className={row.is_out ? 'font-medium text-danger' : 'font-medium text-warning'}>
          <Num value={row.on_hand} variant="table" />
        </span>
      ),
    },
    {
      key: 'threshold',
      header: 'حد هشدار',
      numeric: true,
      cell: (row) => <Num value={row.threshold} variant="table" />,
    },
  ];

  return (
    <AppShell
      title="هشدار موجودی"
      actions={
        <Button variant="outline" asChild>
          <Link href="/inventory">
            <ArrowRightIcon className="size-4 rtl:rotate-180" />
            بازگشت به انبار
          </Link>
        </Button>
      }
    >
      <Head title="هشدار موجودی" />

      <div className="mb-6 grid gap-3 sm:max-w-xs">
        <label className="space-y-1.5">
          <span className="text-2xs text-muted-foreground">انبار</span>
          <Select
            value={filters.warehouse_id === null ? ALL : String(filters.warehouse_id)}
            onValueChange={(value) =>
              router.get(
                '/inventory/low-stock',
                { warehouse_id: value === ALL ? null : value },
                { preserveState: true, preserveScroll: true, replace: true }
              )
            }
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

      {rows.length === 0 ? (
        <EmptyState
          icon={PackageCheckIcon}
          title="هیچ کالایی زیر حد هشدار نیست"
          description="فقط کالاهایی اینجا می‌آیند که برایشان حد هشدار تعیین کرده‌اید. برای بقیه، حد هشدار را در صفحه کالا بگذارید."
          action={
            <Button variant="outline" asChild>
              <Link href="/catalog">رفتن به کالاها</Link>
            </Button>
          }
        />
      ) : (
        <div className="space-y-10">
          {out.length > 0 && (
            <section>
              <h2 className="mb-3 text-sm font-bold text-danger">
                تمام شده (<Num value={out.length} /> قلم)
              </h2>
              <p className="mb-4 text-xs text-muted-foreground">
                این اقلام همین حالا موجودی ندارند؛ اگر مشتری بخواهد، چیزی برای فروش نیست.
              </p>
              <DataTable
                columns={columns}
                rows={out}
                rowKey={(row) => row.variant_id}
                caption="کالاهای تمام‌شده"
              />
            </section>
          )}

          {low.length > 0 && (
            <section>
              <h2 className="mb-3 text-sm font-bold text-warning">
                رو به اتمام (<Num value={low.length} /> قلم)
              </h2>
              <p className="mb-4 text-xs text-muted-foreground">
                موجودی این اقلام به حد هشدارشان رسیده است؛ وقت سفارش دادن است.
              </p>
              <DataTable
                columns={columns}
                rows={low}
                rowKey={(row) => row.variant_id}
                caption="کالاهای رو به اتمام"
              />
            </section>
          )}
        </div>
      )}
    </AppShell>
  );
}
