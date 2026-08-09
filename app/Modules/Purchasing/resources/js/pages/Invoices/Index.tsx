import { Head, Link, router, useForm } from '@inertiajs/react';
import { PlusIcon, TruckIcon } from 'lucide-react';
import { useState } from 'react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { type PartyOption, PartyPicker } from '@/components/domain/party-picker';
import { StatusBadge } from '@/components/domain/status-badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
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

interface InvoiceRow {
  id: number;
  number: string;
  status: string;
  supplier: string | null;
  warehouse: string;
  line_count: number;
  total: MoneyValue;
  issued_at: string | null;
  received_at: string | null;
}

interface Props {
  invoices: { rows: InvoiceRow[]; links: PaginationLink[]; total: number };
  filters: { status: string | null };
  warehouses: { id: number; label: string }[];
}

const STATUSES = [
  { value: 'draft', label: 'پیش‌نویس' },
  { value: 'received', label: 'دریافت‌شده' },
];

const ALL = 'all';

/**
 * Shipments in and out of the shop.
 *
 * A draft is a shopping list someone is still writing; a received invoice is stock,
 * a debt and a set of IMEI passports. The status column is the only thing on this
 * screen that matters, which is why it is not a secondary column.
 */
export default function InvoicesIndex({ invoices, filters, warehouses }: Props) {
  const [opening, setOpening] = useState(false);

  const columns: Column<InvoiceRow>[] = [
    {
      key: 'number',
      header: 'شماره',
      cell: (row) => (
        <Link href={`/purchasing/invoices/${row.id}`} className="font-medium text-primary">
          <Num value={row.number} variant="ltr" />
        </Link>
      ),
    },
    {
      key: 'supplier',
      header: 'تأمین‌کننده',
      cell: (row) => row.supplier ?? <span className="text-muted-foreground">موجودی اولیه</span>,
    },
    {
      key: 'warehouse',
      header: 'انبار',
      secondary: true,
      cell: (row) => row.warehouse,
    },
    {
      key: 'lines',
      header: 'ردیف',
      numeric: true,
      cell: (row) => <Num value={row.line_count} variant="table" />,
    },
    {
      key: 'total',
      header: 'مبلغ',
      numeric: true,
      cell: (row) => <Money rial={row.total.value} digits="latin" />,
    },
    {
      key: 'status',
      header: 'وضعیت',
      cell: (row) => (
        <span className="flex flex-col gap-1">
          <StatusBadge status={row.status === 'received' ? 'final' : row.status} />
          {row.received_at && (
            <span className="text-2xs text-muted-foreground tabular">
              {formatJalali(row.received_at)}
            </span>
          )}
        </span>
      ),
    },
  ];

  return (
    <AppShell
      title="خرید"
      actions={
        <Button onClick={() => setOpening(true)}>
          <PlusIcon className="size-4" />
          فاکتور خرید جدید
        </Button>
      }
    >
      <Head title="خرید" />

      <div className="mb-6 max-w-xs">
        <label className="space-y-1.5">
          <span className="text-2xs text-muted-foreground">وضعیت</span>
          <Select
            value={filters.status ?? ALL}
            onValueChange={(value) =>
              router.get(
                '/purchasing',
                { status: value === ALL ? null : value },
                { preserveState: true, preserveScroll: true, replace: true }
              )
            }
          >
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent dir="rtl">
              <SelectItem value={ALL}>همه فاکتورها</SelectItem>
              {STATUSES.map((status) => (
                <SelectItem key={status.value} value={status.value}>
                  {status.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </label>
      </div>

      <DataTable
        columns={columns}
        rows={invoices.rows}
        rowKey={(row) => row.id}
        caption="فهرست فاکتورهای خرید"
        onRowClick={(row) => router.visit(`/purchasing/invoices/${row.id}`)}
        empty={
          <EmptyState
            icon={TruckIcon}
            title="هنوز خریدی ثبت نشده"
            description="یک فاکتور خرید باز کنید، IMEIها را بچسبانید و دریافت بزنید — کالاها همان لحظه وارد انبار می‌شوند."
            action={<Button onClick={() => setOpening(true)}>فاکتور خرید جدید</Button>}
          />
        }
      />

      <Pagination className="mt-6" links={invoices.links} total={invoices.total} unit="فاکتور" />

      {opening && <OpenDialog warehouses={warehouses} onClose={() => setOpening(false)} />}
    </AppShell>
  );
}

function OpenDialog({
  warehouses,
  onClose,
}: {
  warehouses: { id: number; label: string }[];
  onClose: () => void;
}) {
  const [supplier, setSupplier] = useState<PartyOption | null>(null);
  const form = useForm({
    warehouse_id: String(warehouses[0]?.id ?? ''),
    party_id: null as number | null,
  });

  return (
    <Dialog open onOpenChange={(next) => !next && onClose()}>
      <DialogContent dir="rtl">
        <form
          onSubmit={(event) => {
            event.preventDefault();
            form.transform((data) => ({ ...data, party_id: supplier?.id ?? null }));
            form.post('/purchasing/invoices');
          }}
        >
          <DialogHeader>
            <DialogTitle>فاکتور خرید جدید</DialogTitle>
            <DialogDescription>
              کالاها به انباری که اینجا انتخاب می‌کنید وارد می‌شوند. تأمین‌کننده اختیاری است — برای
              ثبت موجودی اولیه‌ای که از قبل داشته‌اید خالی بگذارید.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-5 py-4">
            <div className="space-y-2">
              <Label htmlFor="purchase-warehouse">انبار مقصد</Label>
              <Select
                value={form.data.warehouse_id}
                onValueChange={(value) => form.setData('warehouse_id', value)}
              >
                <SelectTrigger id="purchase-warehouse" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent dir="rtl">
                  {warehouses.map((warehouse) => (
                    <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                      {warehouse.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {form.errors.warehouse_id && (
                <p className="text-sm text-danger">{form.errors.warehouse_id}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label>تأمین‌کننده (اختیاری)</Label>
              <PartyPicker value={supplier} onChange={setSupplier} kind="supplier" />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              انصراف
            </Button>
            <Button type="submit" disabled={form.processing}>
              {form.processing ? 'در حال ساخت…' : 'ساخت پیش‌نویس'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
