import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRightIcon, HistoryIcon, SearchIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { Pagination } from '@/components/domain/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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

interface Change {
  field: string;
  from: unknown;
  to: unknown;
}

interface ActivityRow {
  id: number;
  description: string;
  event: string | null;
  subject: string | null;
  subject_label: string | null;
  subject_id: number | null;
  causer: string | null;
  created_at: string | null;
  changes: Change[];
}

interface Filters {
  actor: number | null;
  subject: string | null;
  record: number | null;
  from: string | null;
  to: string | null;
  q: string | null;
}

interface Props {
  activities: {
    data: ActivityRow[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
  };
  filters: Filters;
  subjects: { key: string; label: string }[];
  actors: { id: number; name: string }[];
  /** Present only when the screen is one record's history rather than the whole log. */
  record: { label: string; name: string } | null;
}

/** The Select primitive cannot hold an empty value, so "no filter" needs a token. */
const ANY = 'any';

/**
 * Field names as a shopkeeper would say them.
 *
 * Keyed by column name and shared across subjects rather than nested per subject:
 * `name` means the same thing on a product, a party and a price level, and the two
 * places it would not are worth less than the map being one flat thing to read. An
 * unmapped field falls through to its column name — ugly, and better than a blank
 * cell that hides which field moved.
 */
const FIELDS: Record<string, string> = {
  name: 'نام',
  name_fa: 'نام',
  sku: 'کد کالا',
  barcode: 'بارکد',
  price: 'قیمت',
  type: 'نوع',
  description: 'توضیحات',
  category_id: 'دسته‌بندی',
  brand_id: 'برند',
  product_id: 'کالا',
  options: 'ویژگی‌ها',
  low_stock_threshold: 'حد کمبود موجودی',
  is_active: 'وضعیت فعال',
  is_default: 'پیش‌فرض',
  position: 'ترتیب',
  code: 'کد',
  kind: 'نوع طرف حساب',
  company_name: 'نام شرکت',
  national_id: 'کد ملی',
  economic_code: 'کد اقتصادی',
  price_level_id: 'سطح قیمت',
  credit_limit: 'سقف اعتبار',
  opening_balance: 'مانده اولیه',
  birthday: 'تاریخ تولد',
  notes: 'یادداشت',
  email: 'ایمیل',
  mobile: 'موبایل',
};

/** Fields whose value is an integer rial amount and must render through `<Money/>`. */
const MONEY_FIELDS = new Set(['price', 'credit_limit', 'opening_balance']);

export default function ActivityLog({ activities, filters, subjects, actors, record }: Props) {
  const { errors } = usePage().props;

  const [term, setTerm] = useState(filters.q ?? '');
  const [from, setFrom] = useState<string | null>(filters.from);
  const [to, setTo] = useState<string | null>(filters.to);

  const query = (next: Partial<Record<string, string | number | null>> = {}) => {
    const merged: Record<string, string | number | null> = {
      actor: filters.actor,
      subject: filters.subject,
      record: filters.record,
      from: from ? formatJalali(from, { persianDigits: false }) : '',
      to: to ? formatJalali(to, { persianDigits: false }) : '',
      q: term,
      ...next,
    };

    // Empty filters are dropped rather than sent blank. An absent key and an empty one
    // mean the same thing to the server, but not to the reader: audit links get pasted
    // into support threads, and `?subject=product&record=1606` is one somebody can
    // check before they open it.
    return Object.fromEntries(
      Object.entries(merged).filter(([, value]) => value !== null && value !== '')
    );
  };

  const apply = (next: Parameters<typeof query>[0] = {}) => {
    router.get('/settings/activity', query(next), {
      preserveState: true,
      preserveScroll: true,
    });
  };

  // Debounced, so typing a product name does not fire a request per keystroke into a
  // query that scans descriptions.
  useEffect(() => {
    if ((filters.q ?? '') === term) {
      return;
    }

    const timer = setTimeout(() => apply(), 350);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [term]);

  const title = record ? `تاریخچه ${record.label} «${record.name}»` : 'گزارش فعالیت';

  const isFiltered =
    filters.actor !== null ||
    filters.subject !== null ||
    filters.from !== null ||
    filters.to !== null ||
    (filters.q ?? '') !== '';

  return (
    <AppShell
      title={title}
      actions={
        record ? (
          <Button variant="outline" asChild>
            <Link href="/settings/activity">
              <ArrowRightIcon className="size-4" aria-hidden />
              همه‌ی فعالیت‌ها
            </Link>
          </Button>
        ) : undefined
      }
    >
      <Head title={title} />

      <div className="space-y-6">
        {/*
          A filter bar's errors belong to no field the reader is looking at — a
          hand-edited `?from=yesterday` arrives here after a redirect to the clean
          screen, and without this the page would simply appear to have ignored them.
        */}
        {Object.keys(errors).length > 0 && (
          <div
            role="alert"
            data-testid="activity-errors"
            className="space-y-1 rounded-card border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
          >
            <p className="font-medium">فیلتر اعمال نشد:</p>
            <ul className="list-inside list-disc">
              {Object.entries(errors).map(([field, message]) => (
                <li key={field}>{message}</li>
              ))}
            </ul>
          </div>
        )}

        <div className="flex flex-wrap items-end gap-3 rounded-card border border-border bg-surface p-4">
          <div className="grid gap-1.5">
            <Label htmlFor="activity-actor">کاربر</Label>
            <Select
              value={filters.actor !== null ? String(filters.actor) : ANY}
              onValueChange={(value) => apply({ actor: value === ANY ? null : Number(value) })}
            >
              <SelectTrigger id="activity-actor" className="w-44">
                <SelectValue />
              </SelectTrigger>
              <SelectContent dir="rtl">
                <SelectItem value={ANY}>همه‌ی کاربران</SelectItem>
                {actors.map((actor) => (
                  <SelectItem key={actor.id} value={String(actor.id)}>
                    {actor.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="activity-subject">نوع رکورد</Label>
            <Select
              value={filters.subject ?? ANY}
              onValueChange={(value) =>
                // Dropping `record` with the subject on purpose: a record id belongs to
                // one kind of thing, so carrying it across a change of kind would
                // filter for a product that is really a party and show nothing.
                apply({ subject: value === ANY ? null : value, record: null })
              }
            >
              <SelectTrigger id="activity-subject" className="w-44">
                <SelectValue />
              </SelectTrigger>
              <SelectContent dir="rtl">
                <SelectItem value={ANY}>همه‌ی رکوردها</SelectItem>
                {subjects.map((subject) => (
                  <SelectItem key={subject.key} value={subject.key}>
                    {subject.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="activity-from">از تاریخ</Label>
            <JDatePicker
              id="activity-from"
              value={from}
              onChange={(value) => {
                setFrom(value);
                apply({ from: value ? formatJalali(value, { persianDigits: false }) : '' });
              }}
            />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="activity-to">تا تاریخ</Label>
            <JDatePicker
              id="activity-to"
              value={to}
              onChange={(value) => {
                setTo(value);
                apply({ to: value ? formatJalali(value, { persianDigits: false }) : '' });
              }}
            />
          </div>

          <div className="grid grow gap-1.5">
            <Label htmlFor="activity-q">جست‌وجو</Label>
            <div className="relative">
              <SearchIcon
                className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted-foreground"
                aria-hidden
              />
              <Input
                id="activity-q"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                placeholder="مثلاً: قیمت"
                className="ps-9"
              />
            </div>
          </div>

          {isFiltered && (
            <Button variant="ghost" onClick={() => router.get('/settings/activity')}>
              پاک کردن فیلترها
            </Button>
          )}
        </div>

        {activities.data.length === 0 ? (
          <EmptyState
            icon={HistoryIcon}
            title={isFiltered ? 'چیزی با این فیلترها پیدا نشد' : 'هنوز فعالیتی ثبت نشده'}
            description={
              isFiltered
                ? 'بازه‌ی تاریخ را بازتر کنید یا فیلترها را بردارید.'
                : 'هر تغییری که کاربران انجام دهند، همراه با نام و زمان اینجا ثبت می‌شود.'
            }
          />
        ) : (
          <>
            <ul className="divide-y divide-border overflow-hidden rounded-card border border-border bg-surface">
              {activities.data.map((activity) => (
                <ActivityEntry key={activity.id} activity={activity} showLink={record === null} />
              ))}
            </ul>

            <Pagination links={activities.links} total={activities.total} unit="رویداد" />
          </>
        )}
      </div>
    </AppShell>
  );
}

function ActivityEntry({ activity, showLink }: { activity: ActivityRow; showLink: boolean }) {
  // Only worth linking when there is somewhere to go: an entry whose subject is not a
  // registered kind has no history page, and a record already being viewed has one it
  // is already on.
  const href =
    showLink && activity.subject && activity.subject_id
      ? `/settings/activity?subject=${activity.subject}&record=${activity.subject_id}`
      : null;

  return (
    <li className="flex items-start gap-3 p-4">
      <HistoryIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />

      <div className="min-w-0 flex-1 space-y-2">
        <p className="text-sm">
          {activity.description}
          {activity.subject_label && (
            <Badge variant="secondary" className="ms-2">
              {href ? (
                <Link href={href} className="hover:underline">
                  {activity.subject_label}
                </Link>
              ) : (
                activity.subject_label
              )}
            </Badge>
          )}
        </p>

        {activity.changes.length > 0 && (
          <ChangeList changes={activity.changes} event={activity.event} />
        )}

        <p className="text-2xs text-muted-foreground">
          {activity.causer ?? 'سیستم'}
          {activity.created_at && ` · ${formatJalali(activity.created_at, { withTime: true })}`}
        </p>
      </div>
    </li>
  );
}

/**
 * What actually changed, one row per field.
 *
 * This is the half the screen existed without until 11c: the entries were there, the
 * before and after were in the table, and the page rendered neither — so «کی این را
 * عوض کرد» had an answer and «به چه چیزی» did not.
 */
function ChangeList({ changes, event }: { changes: Change[]; event: string | null }) {
  // A creation has no "before". Rendering one anyway gave every field an «— ←» prefix
  // standing in for a value that never existed, which reads as data the log is
  // withholding rather than as a record being born.
  const created = event === 'created';

  return (
    <ul className="space-y-1 border-s-2 border-border ps-3">
      {changes.map((change) => (
        <li key={change.field} className="flex flex-wrap items-center gap-x-2 text-2xs">
          <span className="text-muted-foreground">{FIELDS[change.field] ?? change.field}:</span>

          {!created && (
            <>
              <Value field={change.field} value={change.from} muted />
              <span className="text-muted-foreground" aria-hidden>
                ←
              </span>
            </>
          )}

          <Value field={change.field} value={change.to} />
        </li>
      ))}
    </ul>
  );
}

function Value({ field, value, muted }: { field: string; value: unknown; muted?: boolean }) {
  // The placeholder is never struck through: a line through an em dash renders as a
  // broken glyph, and "this was not set" is not a value that was crossed out.
  if (value === null || value === undefined || value === '') {
    return <span className="text-muted-foreground">—</span>;
  }

  const className = muted ? 'text-muted-foreground line-through' : 'font-medium';

  if (MONEY_FIELDS.has(field) && typeof value === 'number') {
    return (
      <span className={className}>
        <Money rial={value} withUnit />
      </span>
    );
  }

  if (typeof value === 'boolean') {
    return <span className={className}>{value ? 'بله' : 'خیر'}</span>;
  }

  // A threshold or a count is prose here, not a table column, so it takes Persian
  // digits like every other number in a sentence (design-system rule 4).
  if (typeof value === 'number') {
    return (
      <span className={className}>
        <Num value={value} />
      </span>
    );
  }

  if (typeof value === 'object') {
    return <span className={className}>{JSON.stringify(value)}</span>;
  }

  return <span className={className}>{String(value)}</span>;
}
