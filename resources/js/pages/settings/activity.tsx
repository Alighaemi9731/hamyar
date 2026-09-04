import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRightIcon, HistoryIcon } from 'lucide-react';

import { EmptyState } from '@/components/domain/empty-state';
import { FilterBar } from '@/components/domain/filter-bar';
import { FilterSelect } from '@/components/domain/filter-select';
import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { Pagination } from '@/components/domain/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

  // No local copy of the filters: the bar owns the search's debounce, the pickers and
  // selects apply on change, and the server's `filters` is the one state. Dates go on
  // the wire as Jalali strings with Latin digits — a URL somebody pastes into a support
  // thread — and come back as UTC ISO, which is what the picker takes.
  const query = (next: Partial<Record<string, string | number | null>> = {}) => {
    const merged: Record<string, string | number | null> = {
      actor: filters.actor,
      subject: filters.subject,
      record: filters.record,
      from: filters.from ? formatJalali(filters.from, { persianDigits: false }) : '',
      to: filters.to ? formatJalali(filters.to, { persianDigits: false }) : '',
      q: filters.q ?? '',
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

        {/* The same bar every register stands on. The selects and the date pickers
            ride in its children slot; their *all* rows and placeholders name the
            dimension, so the captioned 68px fields this used to draw are gone. */}
        <FilterBar
          search={{
            value: filters.q ?? '',
            label: 'جست‌وجو در فعالیت‌ها',
            placeholder: 'مثلاً: قیمت',
          }}
          onChange={(changes) => apply(changes)}
          resultCount={activities.total}
          resultUnit="رویداد"
        >
          <FilterSelect
            label="کاربر"
            value={filters.actor}
            options={actors.map((actor) => ({ value: String(actor.id), label: actor.name }))}
            allLabel="همه‌ی کاربران"
            onChange={(value) => apply({ actor: value === null ? null : Number(value) })}
          />

          <FilterSelect
            label="نوع رکورد"
            value={filters.subject}
            options={subjects.map((subject) => ({ value: subject.key, label: subject.label }))}
            allLabel="همه‌ی رکوردها"
            // Dropping `record` with the subject on purpose: a record id belongs to one
            // kind of thing, so carrying it across a change of kind would filter for a
            // product that is really a party and show nothing.
            onChange={(value) => apply({ subject: value, record: null })}
          />

          <JDatePicker
            id="activity-from"
            className="w-44"
            value={filters.from}
            placeholder="از تاریخ"
            onChange={(value) =>
              apply({ from: value ? formatJalali(value, { persianDigits: false }) : '' })
            }
          />

          <JDatePicker
            id="activity-to"
            className="w-44"
            value={filters.to}
            placeholder="تا تاریخ"
            onChange={(value) =>
              apply({ to: value ? formatJalali(value, { persianDigits: false }) : '' })
            }
          />

          {/* The bar's own reset clears only what it owns (the search); this one clears
              the dimensions it cannot see. Shown when one of those is set. */}
          {(filters.actor !== null ||
            filters.subject !== null ||
            filters.from !== null ||
            filters.to !== null) && (
            <Button variant="ghost" onClick={() => router.get('/settings/activity')}>
              پاک کردن همه
            </Button>
          )}
        </FilterBar>

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
              {/* Inside a badge in a sentence, so it cannot grow — but it is the only way
                  into the record from the log, and it measured 17px. Invisible padding gives
                  it 41px (a 17px line + 24) without moving the badge. */}
              {href ? (
                <Link href={href} className="-my-3 inline-block py-3 hover:underline">
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
