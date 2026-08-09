import { Head, Link, router } from '@inertiajs/react';
import { SmartphoneIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';
import type { MoneyValue } from '@/types';

interface UnitRow {
  id: number;
  imei1: string | null;
  serial: string | null;
  product_name: string;
  variant_name: string;
  status: string;
  condition_label: string;
  grade: string | null;
  warehouse_name: string | null;
  acquired_from: string | null;
  acquired_at: string | null;
  cost: MoneyValue | null;
}

interface Filters {
  q: string;
  status: string | null;
  warehouse_id: number | null;
  condition: string | null;
  hamta: string | null;
}

interface Props {
  units: { rows: UnitRow[]; links: PaginationLink[]; total: number };
  filters: Filters;
  statuses: { value: string; label: string }[];
  conditions: { value: string; label: string }[];
  warehouses: { id: number; label: string }[];
  can: { view_cost: boolean };
}

const ALL = 'all';

const HAMTA_OPTIONS = [
  { value: 'pending', label: 'انتقال انجام نشده' },
  { value: 'done', label: 'انتقال انجام شده' },
  { value: 'not_required', label: 'لازم ندارد' },
];

/**
 * The serialized register: every handset the shop has ever held.
 *
 * The search box takes an IMEI, a serial or a model name, because the person using it
 * has one of those three in front of them and does not know which field it lives in.
 * Every row is a door into that device's passport — that page is the point, this one
 * is how you reach it.
 */
export default function UnitsIndex({
  units,
  filters,
  statuses,
  conditions,
  warehouses,
  can,
}: Props) {
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

  function visit(changes: Record<string, string | number | boolean | null>): void {
    // `filters` carries the server's idea of `q`, which lags a keystroke behind the
    // box; the local term is spread after it so the two never disagree.
    router.get(
      '/inventory/units',
      { ...filters, q: term, ...changes },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  const columns: Column<UnitRow>[] = [
    {
      key: 'code',
      header: 'IMEI / سریال',
      cell: (row) =>
        (row.imei1 ?? row.serial) ? (
          <Num value={(row.imei1 ?? row.serial) as string} variant="ltr" />
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: 'product',
      header: 'دستگاه',
      cell: (row) => (
        <span className="flex min-w-0 flex-col">
          <Link href={`/inventory/units/${row.id}`} className="truncate font-medium text-primary">
            {row.product_name}
          </Link>
          <span className="truncate text-2xs text-muted-foreground">
            {row.variant_name}
            {row.grade && ` · درجه ${row.grade}`}
          </span>
        </span>
      ),
    },
    {
      key: 'status',
      header: 'وضعیت',
      cell: (row) => <StatusBadge status={row.status} />,
    },
    {
      key: 'warehouse',
      header: 'انبار',
      secondary: true,
      cell: (row) => row.warehouse_name ?? <span className="text-muted-foreground">—</span>,
    },
    {
      key: 'acquired',
      header: 'خرید از',
      secondary: true,
      cell: (row) => (
        <span className="flex min-w-0 flex-col">
          <span className="truncate">{row.acquired_from ?? '—'}</span>
          {row.acquired_at && (
            <span className="text-2xs text-muted-foreground tabular">
              {formatJalali(row.acquired_at)}
            </span>
          )}
        </span>
      ),
    },
    // Cost only exists in the payload when the signed-in user may see it (Gate 1's
    // Salesperson boundary), so the column is absent rather than empty.
    ...(can.view_cost
      ? [
          {
            key: 'cost',
            header: 'بهای خرید',
            numeric: true,
            cell: (row: UnitRow) =>
              row.cost ? <Money rial={row.cost.value} digits="latin" /> : null,
          },
        ]
      : []),
  ];

  return (
    <AppShell title="دستگاه‌های سریال‌دار">
      <Head title="دستگاه‌های سریال‌دار" />

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <FilterSelect
          label="وضعیت"
          value={filters.status}
          options={statuses}
          allLabel="همه وضعیت‌ها"
          onChange={(value) => visit({ status: value })}
        />
        <FilterSelect
          label="انبار"
          value={filters.warehouse_id}
          options={warehouses.map((w) => ({ value: String(w.id), label: w.label }))}
          allLabel="همه انبارها"
          onChange={(value) => visit({ warehouse_id: value })}
        />
        <FilterSelect
          label="وضعیت ظاهری"
          value={filters.condition}
          options={conditions}
          allLabel="نو و دست‌دوم"
          onChange={(value) => visit({ condition: value })}
        />
        <FilterSelect
          label="همتا"
          value={filters.hamta}
          options={HAMTA_OPTIONS}
          allLabel="بدون فیلتر همتا"
          onChange={(value) => visit({ hamta: value })}
        />
      </div>

      <DataTable
        columns={columns}
        rows={units.rows}
        rowKey={(row) => row.id}
        caption="فهرست دستگاه‌های سریال‌دار فروشگاه"
        search={{ value: term, onChange: setTerm, placeholder: 'IMEI، سریال یا نام دستگاه…' }}
        onRowClick={(row) => router.visit(`/inventory/units/${row.id}`)}
        empty={
          <EmptyState
            icon={SmartphoneIcon}
            title="هنوز دستگاهی ثبت نشده"
            description="دستگاه‌ها با ثبت فاکتور خرید و وارد کردن IMEIها ساخته می‌شوند."
            action={
              <Button variant="outline" asChild>
                <Link href="/catalog">رفتن به کالاها</Link>
              </Button>
            }
          />
        }
      />

      <Pagination className="mt-6" links={units.links} total={units.total} unit="دستگاه" />
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
