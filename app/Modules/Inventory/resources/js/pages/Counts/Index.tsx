import { Head, Link, router, useForm } from '@inertiajs/react';
import { ClipboardListIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { type PaginationLink, Pagination } from '@/components/domain/pagination';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

interface CountRow {
  id: number;
  number: string;
  status: string;
  warehouse: string;
  is_blind: boolean;
  line_count: number;
  applied_at: string | null;
}

interface Props {
  counts: { rows: CountRow[]; links: PaginationLink[]; total: number };
  warehouses: { id: number; label: string }[];
}

const STATUS_LABELS: Record<string, { label: string; tone: string }> = {
  open: { label: 'باز', tone: 'text-warning' },
  applied: { label: 'اعمال شد', tone: 'text-success' },
  canceled: { label: 'لغو شد', tone: 'text-muted-foreground' },
};

/** Stock count sessions. */
export default function CountsIndex({ counts, warehouses }: Props) {
  const [creating, setCreating] = useState(false);

  const columns: Column<CountRow>[] = [
    {
      key: 'number',
      header: 'شماره',
      cell: (row) => (
        <Link href={`/inventory/counts/${row.id}`} className="font-medium text-primary">
          <Num value={row.number} variant="ltr" />
        </Link>
      ),
    },
    { key: 'warehouse', header: 'انبار', cell: (row) => row.warehouse },
    {
      key: 'blind',
      header: 'نوع',
      secondary: true,
      cell: (row) => (
        <span className="text-2xs text-muted-foreground">
          {row.is_blind ? 'کور' : 'با نمایش موجودی'}
        </span>
      ),
    },
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
          {row.applied_at && (
            <span className="text-2xs text-muted-foreground tabular">
              {formatJalali(row.applied_at)}
            </span>
          )}
        </span>
      ),
    },
  ];

  return (
    <AppShell
      title="انبارگردانی"
      actions={
        <Button onClick={() => setCreating(true)}>
          <PlusIcon className="size-4" />
          انبارگردانی جدید
        </Button>
      }
    >
      <Head title="انبارگردانی" />

      <DataTable
        columns={columns}
        rows={counts.rows}
        rowKey={(row) => row.id}
        caption="فهرست انبارگردانی‌ها"
        onRowClick={(row) => router.visit(`/inventory/counts/${row.id}`)}
        empty={
          <EmptyState
            icon={ClipboardListIcon}
            title="هنوز انبارگردانی ثبت نشده"
            description="یک برگه شمارش باز کنید، آنچه روی قفسه هست را بشمارید و اختلاف را به‌عنوان تعدیل ثبت کنید."
            action={<Button onClick={() => setCreating(true)}>انبارگردانی جدید</Button>}
          />
        }
      />

      <Pagination className="mt-6" links={counts.links} total={counts.total} unit="برگه" />

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
    warehouse_id: String(warehouses[0]?.id ?? ''),
    is_blind: true,
  });

  return (
    <Dialog open onOpenChange={(next) => !next && onClose()}>
      <DialogContent dir="rtl">
        <form
          onSubmit={(event) => {
            event.preventDefault();
            form.post('/inventory/counts');
          }}
        >
          <DialogHeader>
            <DialogTitle>انبارگردانی جدید</DialogTitle>
            <DialogDescription>
              موجودی مورد انتظار در لحظه افزودن هر ردیف ثبت می‌شود، بنابراین اختلاف نسبت به همان
              لحظه سنجیده می‌شود.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-5 py-4">
            <div className="space-y-2">
              <Label htmlFor="count-warehouse">انبار</Label>
              <Select
                value={form.data.warehouse_id}
                onValueChange={(value) => form.setData('warehouse_id', value)}
              >
                <SelectTrigger id="count-warehouse" className="w-full">
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
            </div>

            <Checkbox
              checked={form.data.is_blind}
              onCheckedChange={(checked) => form.setData('is_blind', checked === true)}
              label="شمارش کور"
              description="موجودی مورد انتظار به شمارنده نشان داده نمی‌شود. عددی که روی صفحه باشد، عددی است که آدم‌ها به سمتش می‌شمارند."
            />
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              انصراف
            </Button>
            <Button type="submit" disabled={form.processing}>
              {form.processing ? 'در حال ساخت…' : 'شروع انبارگردانی'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
