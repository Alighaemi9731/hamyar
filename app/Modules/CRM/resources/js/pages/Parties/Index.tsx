import { Head, Link, router } from '@inertiajs/react';
import { BellIcon, PlusIcon, UploadIcon, UsersIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { type Column, DataTable } from '@/components/domain/data-table';
import { EmptyState } from '@/components/domain/empty-state';
import { Money } from '@/components/domain/money';
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
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

interface PartyRow {
  id: number;
  name: string;
  company_name: string | null;
  kind: string;
  kind_label: string;
  mobile: string | null;
  is_active: boolean;
  balance: MoneyValue | null;
}

interface Props {
  parties: { rows: PartyRow[]; links: PaginationLink[]; total: number };
  filters: { q: string; kind: string | null; include_inactive: boolean };
  kinds: { value: string; label: string }[];
  can: { create: boolean; view_balance: boolean };
}

const ALL = 'all';

/**
 * Everyone the shop deals with, on one list.
 *
 * Searching covers name, company, national id and every stored phone number, because
 * the counter does not know which of those the person will give them — the same query
 * the picker uses, so the two screens can never disagree about who exists.
 */
export default function PartiesIndex({ parties, filters, kinds, can }: Props) {
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

  function visit(changes: Record<string, string | boolean | null>): void {
    router.get(
      '/crm',
      {
        q: term,
        kind: filters.kind,
        include_inactive: filters.include_inactive,
        ...changes,
      },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  }

  const columns: Column<PartyRow>[] = [
    {
      key: 'name',
      header: 'نام',
      cell: (row) => (
        <span className="flex min-w-0 flex-col">
          <Link href={`/crm/parties/${row.id}`} className="truncate font-medium text-primary">
            {row.name}
          </Link>
          {row.company_name && (
            <span className="truncate text-2xs text-muted-foreground">{row.company_name}</span>
          )}
        </span>
      ),
    },
    {
      key: 'kind',
      header: 'نوع',
      cell: (row) => (
        <Badge variant="outline" className="rounded-full font-normal">
          {row.kind_label}
        </Badge>
      ),
    },
    {
      key: 'mobile',
      header: 'شماره همراه',
      secondary: true,
      cell: (row) =>
        row.mobile ? (
          <Num value={row.mobile} variant="ltr" />
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    ...(can.view_balance
      ? [
          {
            key: 'balance',
            header: 'مانده',
            numeric: true,
            // The word carries the direction, so the figure never needs a minus sign
            // that RTL would throw to the wrong side of the number.
            cell: (row: PartyRow) => {
              const balance = row.balance?.value ?? 0;

              if (balance === 0) {
                return <span className="text-2xs text-muted-foreground">تسویه</span>;
              }

              return (
                <span
                  className={cn(
                    'inline-flex items-center gap-1 text-sm',
                    balance > 0 ? 'text-warning' : 'text-muted-foreground'
                  )}
                >
                  {balance > 0 ? 'بدهکار' : 'بستانکار'}
                  <Money rial={Math.abs(balance)} digits="latin" />
                </span>
              );
            },
          } satisfies Column<PartyRow>,
        ]
      : []),
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
      title="مشتریان و طرف حساب‌ها"
      actions={
        <>
          <Button variant="outline" asChild>
            <Link href="/crm/follow-ups">
              <BellIcon className="size-4" />
              میز پیگیری
            </Link>
          </Button>
          <Button variant="outline" asChild>
            <Link href="/crm/import">
              <UploadIcon className="size-4" />
              ورود گروهی
            </Link>
          </Button>
          {can.create && (
            <Button asChild>
              <Link href="/crm/parties/create">
                <PlusIcon className="size-4" />
                طرف حساب جدید
              </Link>
            </Button>
          )}
        </>
      }
    >
      <Head title="مشتریان و طرف حساب‌ها" />

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label className="space-y-1.5">
          <span className="text-2xs text-muted-foreground">نوع</span>
          <Select
            value={filters.kind ?? ALL}
            onValueChange={(value) => visit({ kind: value === ALL ? null : value })}
          >
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent dir="rtl">
              <SelectItem value={ALL}>همه</SelectItem>
              {kinds.map((kind) => (
                <SelectItem key={kind.value} value={kind.value}>
                  {kind.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </label>

        <label className="space-y-1.5">
          <span className="text-2xs text-muted-foreground">غیرفعال‌ها</span>
          <Select
            value={filters.include_inactive ? '1' : ALL}
            onValueChange={(value) => visit({ include_inactive: value === '1' })}
          >
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent dir="rtl">
              <SelectItem value={ALL}>پنهان باشند</SelectItem>
              <SelectItem value="1">نمایش داده شوند</SelectItem>
            </SelectContent>
          </Select>
        </label>
      </div>

      <DataTable
        columns={columns}
        rows={parties.rows}
        rowKey={(row) => row.id}
        caption="فهرست طرف حساب‌ها"
        search={{ value: term, onChange: setTerm, placeholder: 'نام، شرکت، کد ملی یا شماره تماس…' }}
        onRowClick={(row) => router.visit(`/crm/parties/${row.id}`)}
        empty={
          <EmptyState
            icon={UsersIcon}
            title="هنوز طرف حسابی ثبت نشده"
            description="مشتری‌ها و تأمین‌کننده‌ها در یک فهرست‌اند — همان کسی که به شما گوشی می‌فروشد، ممکن است فردا از شما شارژر بخرد."
            action={
              can.create ? (
                <Button asChild>
                  <Link href="/crm/parties/create">ثبت اولین طرف حساب</Link>
                </Button>
              ) : undefined
            }
          />
        }
      />

      <Pagination className="mt-6" links={parties.links} total={parties.total} unit="طرف حساب" />
    </AppShell>
  );
}
