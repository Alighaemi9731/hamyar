import { Head, Link, router, useForm } from '@inertiajs/react';
import { PlusIcon, TruckIcon } from 'lucide-react';
import { useState } from 'react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
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

interface TransferRow {
  id: number;
  number: string;
  status: string;
  from: string;
  to: string;
  line_count: number;
  dispatched_at: string | null;
  received_at: string | null;
}

interface Props {
  transfers: { rows: TransferRow[]; links: PaginationLink[]; total: number };
  warehouses: { id: number; label: string }[];
}

const STATUS_LABELS: Record<string, { label: string; tone: string }> = {
  draft: { label: 'پیش‌نویس', tone: 'text-muted-foreground' },
  dispatched: { label: 'در راه', tone: 'text-warning' },
  received: { label: 'تحویل شد', tone: 'text-success' },
  canceled: { label: 'لغو شد', tone: 'text-muted-foreground' },
};

/**
 * Transfers between warehouses.
 *
 * "در راه" is a real state, not a formality: stock has left the source and has not
 * arrived anywhere, so it cannot be sold at either end. That is what a one-step
 * transfer gets wrong — a van full of phones sellable in two shops at once.
 */
export default function TransfersIndex({ transfers, warehouses }: Props) {
  const [creating, setCreating] = useState(false);

  const columns: Column<TransferRow>[] = [
    {
      key: 'number',
      header: 'شماره',
      cell: (row) => (
        <Link href={`/inventory/transfers/${row.id}`} className="font-medium text-primary">
          <Num value={row.number} variant="ltr" />
        </Link>
      ),
    },
    { key: 'from', header: 'از انبار', cell: (row) => row.from },
    { key: 'to', header: 'به انبار', cell: (row) => row.to },
    {
      key: 'lines',
      header: 'ردیف',
      numeric: true,
      cell: (row) => <Num value={row.line_count} variant="table" />,
    },
    {
      key: 'status',
      header: 'وضعیت',
      cell: (row) => (
        <span className="flex flex-col">
          <span className={STATUS_LABELS[row.status]?.tone ?? 'text-muted-foreground'}>
            {STATUS_LABELS[row.status]?.label ?? row.status}
          </span>
          {(row.received_at ?? row.dispatched_at) && (
            <span className="text-2xs text-muted-foreground tabular">
              {formatJalali(row.received_at ?? row.dispatched_at)}
            </span>
          )}
        </span>
      ),
    },
  ];

  return (
    <AppShell
      title="حواله‌های انبار"
      actions={
        <Button onClick={() => setCreating(true)}>
          <PlusIcon className="size-4" />
          حواله جدید
        </Button>
      }
    >
      <Head title="حواله‌های انبار" />

      <DataTable
        columns={columns}
        rows={transfers.rows}
        rowKey={(row) => row.id}
        caption="فهرست حواله‌های انبار"
        onRowClick={(row) => router.visit(`/inventory/transfers/${row.id}`)}
        empty={
          <EmptyState
            icon={TruckIcon}
            title="هنوز حواله‌ای صادر نشده"
            description="برای جابه‌جایی کالا بین انبارها یا شعبه‌ها، یک حواله بسازید. کالا هنگام ارسال از مبدأ کم و هنگام تحویل به مقصد اضافه می‌شود."
            action={<Button onClick={() => setCreating(true)}>حواله جدید</Button>}
          />
        }
      />

      <Pagination className="mt-6" links={transfers.links} total={transfers.total} unit="حواله" />

      {creating && <CreateDialog warehouses={warehouses} onClose={() => setCreating(false)} />}
    </AppShell>
  );
}

function CreateDialog({
  warehouses,
  onClose,
}: {
  warehouses: { id: number; label: string }[];
  onClose: () => void;
}) {
  const form = useForm({
    from_warehouse_id: String(warehouses[0]?.id ?? ''),
    to_warehouse_id: String(warehouses[1]?.id ?? warehouses[0]?.id ?? ''),
  });

  return (
    <Dialog open onOpenChange={(next) => !next && onClose()}>
      <DialogContent dir="rtl">
        <form
          onSubmit={(event) => {
            event.preventDefault();
            form.post('/inventory/transfers');
          }}
        >
          <DialogHeader>
            <DialogTitle>حواله جدید</DialogTitle>
            <DialogDescription>
              حواله در حالت پیش‌نویس ساخته می‌شود؛ ردیف‌ها را اضافه کنید و بعد «ارسال» بزنید.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-5 py-4">
            {(
              [
                ['from_warehouse_id', 'از انبار'],
                ['to_warehouse_id', 'به انبار'],
              ] as const
            ).map(([field, label]) => (
              <div key={field} className="space-y-2">
                <Label htmlFor={field}>{label}</Label>
                <Select
                  value={form.data[field]}
                  onValueChange={(value) => form.setData(field, value)}
                >
                  <SelectTrigger id={field} className="w-full">
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
                {form.errors[field] && <p className="text-sm text-danger">{form.errors[field]}</p>}
              </div>
            ))}

            {warehouses.length < 2 && (
              <p className="text-xs text-warning">
                برای صدور حواله دست‌کم دو انبار لازم است. از تنظیمات، انبار دوم را بسازید.
              </p>
            )}
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              انصراف
            </Button>
            <Button type="submit" disabled={form.processing || warehouses.length < 2}>
              {form.processing ? 'در حال ساخت…' : 'ساخت حواله'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
