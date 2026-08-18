import { Head, Link, useForm } from '@inertiajs/react';
import {
  ArrowRightIcon,
  BellPlusIcon,
  CheckIcon,
  PencilIcon,
  RotateCcwIcon,
  SparklesIcon,
} from 'lucide-react';
import { useState } from 'react';

import { HistoryLink } from '@/components/domain/history-link';
import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { StatCard } from '@/components/domain/stat-card';
import { type TimelineItem, Timeline } from '@/components/domain/timeline';
import { SettingsSection } from '@/components/settings-section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { AppShell } from '@/layouts/app-shell';
import { toLatinDigits } from '@/lib/digits';
import { formError } from '@/lib/forms';
import { formatJalali } from '@/lib/jalali';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

interface Party {
  id: number;
  name: string;
  company_name: string | null;
  kind: string;
  kind_label: string;
  national_id: string | null;
  economic_code: string | null;
  price_level: string | null;
  birthday: string | null;
  is_active: boolean;
  notes: string | null;
  tags: { id: number; name: string; colour: string | null }[];
}

interface StatementRow {
  id: number;
  occurred_at: string;
  description: string | null;
  debit: MoneyValue;
  credit: MoneyValue;
  balance: MoneyValue;
}

interface Finance {
  balance: MoneyValue;
  opening_balance: MoneyValue;
  credit_limit: MoneyValue | null;
  exceeds_limit: boolean;
  statement: StatementRow[];
}

interface FollowUp {
  id: number;
  title: string;
  body: string | null;
  due_at: string;
  done_at: string | null;
  assignee: string | null;
  is_overdue: boolean;
}

interface Props {
  party: Party;
  contacts: {
    id: number;
    type: string;
    value: string;
    label: string | null;
    is_primary: boolean;
  }[];
  addresses: {
    id: number;
    label: string | null;
    city: string | null;
    province: string | null;
    line: string | null;
    postal_code: string | null;
  }[];
  finance: Finance | null;
  timeline: TimelineItem[];
  timeline_failed: string[];
  follow_ups: FollowUp[];
  loyalty: {
    balance: number;
    rule: { name: string; rial_per_point: number; expires_after_months: number | null } | null;
  };
  can: { update: boolean; view_balance: boolean; view_activity: boolean };
}

/**
 * The customer page — the screen Phase 4 exists for.
 *
 * A shop opens it to answer one of three questions: what do they owe, what have we
 * done for them, and what did we promise. The balance is a SUM over the ledger, never
 * a stored figure, so it and the statement beside it cannot disagree; the timeline is
 * assembled from every module rather than from CRM's tables, so it keeps growing as
 * Sales and Repairs land without this page changing.
 */
export default function PartyShow({
  party,
  contacts,
  addresses,
  finance,
  timeline,
  timeline_failed: timelineFailed,
  follow_ups: followUps,
  loyalty,
  can,
}: Props) {
  return (
    <AppShell
      title={party.name}
      actions={
        <>
          {/* «کی سقف اعتبار این مشتری را بالا برد؟» — the balance is a SUM over
              ledger_entries and records nobody; the ceiling change is audited. */}
          {can.view_activity && <HistoryLink subject="party" record={party.id} />}

          <Button variant="outline" asChild>
            <Link href="/crm">
              <ArrowRightIcon className="size-4 rtl:rotate-180" />
              بازگشت
            </Link>
          </Button>
          {can.update && (
            <Button variant="outline" asChild>
              <Link href={`/crm/parties/${party.id}/edit`}>
                <PencilIcon className="size-4" />
                ویرایش
              </Link>
            </Button>
          )}
        </>
      }
    >
      <Head title={party.name} />

      <Identity party={party} contacts={contacts} addresses={addresses} />

      {finance && <Finances finance={finance} />}

      <div className="mt-6 grid gap-6 lg:grid-cols-[1.6fr_1fr] lg:items-start">
        <Tabs defaultValue="timeline" className="min-w-0">
          <TabsList>
            <TabsTrigger value="timeline">رویدادها</TabsTrigger>
            {finance && <TabsTrigger value="statement">صورتحساب</TabsTrigger>}
          </TabsList>

          <TabsContent value="timeline" className="pt-6">
            <Timeline items={timeline} failed={timelineFailed} />
          </TabsContent>

          {finance && (
            <TabsContent value="statement" className="pt-6">
              <Statement rows={finance.statement} />
            </TabsContent>
          )}
        </Tabs>

        <div className="space-y-6">
          <NoteForm partyId={party.id} />
          <FollowUps partyId={party.id} followUps={followUps} canManage={can.update} />
          <Loyalty partyId={party.id} loyalty={loyalty} canManage={can.update} />
        </div>
      </div>
    </AppShell>
  );
}

/* --------------------------------------------------------------- identity -- */

function Identity({ party, contacts, addresses }: Pick<Props, 'party' | 'contacts' | 'addresses'>) {
  return (
    <section className="rounded-card border border-border bg-card p-6 sm:p-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0 space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="outline" className="rounded-full font-normal">
              {party.kind_label}
            </Badge>
            {!party.is_active && (
              <Badge
                variant="outline"
                className="rounded-full border-border font-normal text-muted-foreground"
              >
                غیرفعال
              </Badge>
            )}
            {party.tags.map((tag) => (
              <Badge key={tag.id} variant="outline" className="rounded-full font-normal">
                {tag.name}
              </Badge>
            ))}
          </div>

          {party.company_name && (
            <p className="text-sm text-muted-foreground">{party.company_name}</p>
          )}
        </div>
      </div>

      <dl className="mt-6 grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
        {contacts.map((contact) => (
          <Fact key={contact.id} label={contact.label ?? contactLabel(contact.type)}>
            {contact.type === 'email' ? (
              <span className="ltr-value" dir="ltr">
                {contact.value}
              </span>
            ) : (
              <Num value={contact.value} variant="ltr" />
            )}
          </Fact>
        ))}

        {party.national_id && (
          <Fact label="کد ملی">
            <Num value={party.national_id} variant="ltr" />
          </Fact>
        )}
        {party.economic_code && (
          <Fact label="کد اقتصادی">
            <Num value={party.economic_code} variant="ltr" />
          </Fact>
        )}
        {party.price_level && <Fact label="سطح قیمت">{party.price_level}</Fact>}
        {party.birthday && (
          <Fact label="تاریخ تولد">
            <span className="tabular">{formatJalali(party.birthday, { longMonth: true })}</span>
          </Fact>
        )}

        {addresses.map((address) => (
          <Fact key={address.id} label={address.label ?? 'نشانی'}>
            {[address.province, address.city, address.line].filter(Boolean).join('، ')}
          </Fact>
        ))}
      </dl>

      {party.notes && (
        <p className="mt-6 border-t border-border pt-6 text-sm leading-relaxed text-muted-foreground">
          {party.notes}
        </p>
      )}
    </section>
  );
}

function contactLabel(type: string): string {
  return type === 'mobile' ? 'همراه' : type === 'phone' ? 'تلفن' : 'ایمیل';
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="min-w-0 space-y-1">
      <dt className="text-2xs text-muted-foreground">{label}</dt>
      <dd className="truncate text-sm">{children}</dd>
    </div>
  );
}

/* ---------------------------------------------------------------- finance -- */

function Finances({ finance }: { finance: Finance }) {
  const owes = finance.balance.value > 0;

  return (
    <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <StatCard
        label={owes ? 'بدهکار است' : finance.balance.value === 0 ? 'تسویه' : 'بستانکار است'}
        value={Math.abs(finance.balance.value)}
        isMoney
        tone={finance.exceeds_limit ? 'danger' : owes ? 'warning' : 'neutral'}
        hint={finance.exceeds_limit ? 'از سقف اعتبار گذشته' : undefined}
      />

      <StatCard label="مانده اولیه" value={finance.opening_balance.value} isMoney />

      {finance.credit_limit ? (
        <StatCard
          label="سقف اعتبار"
          value={finance.credit_limit.value}
          isMoney
          hint="فروش اعتباری بیش از این، هشدار می‌دهد اما بسته نمی‌شود"
        />
      ) : (
        // Null, not zero: nobody has set a limit, which is not the same as a limit of
        // nothing — and the card would otherwise state a decision the shop never made.
        <StatCard label="سقف اعتبار" value={null} hint="تعیین نشده" />
      )}
    </div>
  );
}

function Statement({ rows }: { rows: StatementRow[] }) {
  if (rows.length === 0) {
    return (
      <p className="rounded-card border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground">
        هنوز گردشی برای این طرف حساب ثبت نشده است.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto rounded-card border border-border">
      <Table>
        <caption className="sr-only">صورتحساب طرف حساب</caption>
        <TableHeader>
          <TableRow>
            <TableHead>تاریخ</TableHead>
            <TableHead>شرح</TableHead>
            <TableHead className="text-end">بدهکار</TableHead>
            <TableHead className="text-end">بستانکار</TableHead>
            <TableHead className="text-end">مانده</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow key={row.id}>
              <TableCell className="whitespace-nowrap tabular text-xs">
                {formatJalali(row.occurred_at)}
              </TableCell>
              <TableCell className="text-sm">{row.description ?? '—'}</TableCell>
              <TableCell className="text-end tabular">
                {row.debit.value > 0 ? <Money rial={row.debit.value} digits="latin" /> : '—'}
              </TableCell>
              <TableCell className="text-end tabular">
                {row.credit.value > 0 ? <Money rial={row.credit.value} digits="latin" /> : '—'}
              </TableCell>
              <TableCell className="text-end tabular font-medium">
                <Money rial={row.balance.value} digits="latin" />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

/* ------------------------------------------------------------------ notes -- */

function NoteForm({ partyId }: { partyId: number }) {
  const form = useForm({ body: '' });

  return (
    <SettingsSection
      title="یادداشت"
      description="آنچه گفته شد، با تاریخ و نام ثبت‌کننده. یادداشت‌ها ویرایش نمی‌شوند."
    >
      <form
        className="space-y-3"
        onSubmit={(event) => {
          event.preventDefault();
          form.post(`/crm/parties/${partyId}/notes`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
          });
        }}
      >
        <Textarea
          rows={3}
          value={form.data.body}
          placeholder="مثلاً: گفت هفته آینده برای گارانتی می‌آید."
          onChange={(event) => form.setData('body', event.target.value)}
          aria-invalid={Boolean(form.errors.body)}
        />
        {form.errors.body && <p className="text-sm text-danger">{form.errors.body}</p>}

        <Button type="submit" disabled={form.processing || form.data.body.trim() === ''}>
          ثبت یادداشت
        </Button>
      </form>
    </SettingsSection>
  );
}

/* ------------------------------------------------------------- follow-ups -- */

function FollowUps({
  partyId,
  followUps,
  canManage,
}: {
  partyId: number;
  followUps: FollowUp[];
  canManage: boolean;
}) {
  const [adding, setAdding] = useState(false);
  const form = useForm({ title: '', body: '', due_at: '' });
  const toggle = useForm({});

  return (
    <SettingsSection
      title="پیگیری‌ها"
      description="قرارِ تماس بعدی. سررسیدها در «میز پیگیری» کنار هم دیده می‌شوند."
    >
      {followUps.length === 0 && !adding && (
        <p className="text-sm text-muted-foreground">پیگیری بازی برای این طرف حساب نیست.</p>
      )}

      <ul className="space-y-2">
        {followUps.map((followUp) => (
          <li
            key={followUp.id}
            className="flex min-h-11 flex-wrap items-start gap-3 rounded-control border border-border px-3 py-2"
          >
            <span className="min-w-0 flex-1">
              <span
                className={cn(
                  'block text-sm',
                  followUp.done_at && 'text-muted-foreground line-through'
                )}
              >
                {followUp.title}
              </span>
              <span className="flex flex-wrap items-center gap-x-2 text-2xs text-muted-foreground">
                <span className="tabular">
                  {formatJalali(followUp.due_at, { longMonth: true })}
                </span>
                {followUp.assignee && <span>· {followUp.assignee}</span>}
                {followUp.is_overdue && <span className="text-danger">· گذشته</span>}
              </span>
            </span>

            <Button
              type="button"
              variant="ghost"
              size="icon"
              aria-label={followUp.done_at ? 'بازکردن دوباره' : 'انجام شد'}
              disabled={toggle.processing}
              onClick={() => toggle.put(`/crm/follow-ups/${followUp.id}`, { preserveScroll: true })}
            >
              {followUp.done_at ? (
                <RotateCcwIcon className="size-4" />
              ) : (
                <CheckIcon className="size-4 text-success" />
              )}
            </Button>
          </li>
        ))}
      </ul>

      {canManage &&
        (adding ? (
          <form
            className="mt-4 space-y-3 border-t border-border pt-4"
            onSubmit={(event) => {
              event.preventDefault();
              form.post(`/crm/parties/${partyId}/follow-ups`, {
                preserveScroll: true,
                onSuccess: () => {
                  form.reset();
                  setAdding(false);
                },
              });
            }}
          >
            <div className="space-y-2">
              <Label htmlFor="follow-up-title">موضوع</Label>
              <Input
                id="follow-up-title"
                value={form.data.title}
                autoFocus
                onChange={(event) => form.setData('title', event.target.value)}
              />
              {form.errors.title && <p className="text-sm text-danger">{form.errors.title}</p>}
            </div>

            <div className="space-y-2">
              <Label htmlFor="follow-up-due">سررسید</Label>
              <JDatePicker
                id="follow-up-due"
                value={form.data.due_at}
                onChange={(value) => form.setData('due_at', value ?? '')}
              />
              {form.errors.due_at && <p className="text-sm text-danger">{form.errors.due_at}</p>}
            </div>

            <div className="flex gap-2">
              <Button type="submit" disabled={form.processing}>
                ثبت پیگیری
              </Button>
              <Button type="button" variant="outline" onClick={() => setAdding(false)}>
                انصراف
              </Button>
            </div>
          </form>
        ) : (
          <Button type="button" variant="outline" className="mt-4" onClick={() => setAdding(true)}>
            <BellPlusIcon className="size-4" />
            پیگیری جدید
          </Button>
        ))}
    </SettingsSection>
  );
}

/* ---------------------------------------------------------------- loyalty -- */

function Loyalty({
  partyId,
  loyalty,
  canManage,
}: {
  partyId: number;
  loyalty: Props['loyalty'];
  canManage: boolean;
}) {
  const [open, setOpen] = useState(false);
  const form = useForm({ points: '', description: '' });

  return (
    <SettingsSection
      title="امتیاز وفاداری"
      description={
        loyalty.rule
          ? `${loyalty.rule.name} — هر ${loyalty.rule.rial_per_point.toLocaleString('en-US')} ریال، یک امتیاز.`
          : 'هنوز قاعده‌ای برای امتیاز تعریف نشده است.'
      }
    >
      <p className="flex items-baseline gap-2">
        <SparklesIcon className="size-4 text-info" aria-hidden />
        <span className="text-xl font-semibold tabular">
          <Num value={loyalty.balance} />
        </span>
        <span className="text-sm text-muted-foreground">امتیاز</span>
      </p>

      {canManage &&
        (open ? (
          <form
            className="mt-4 space-y-3 border-t border-border pt-4"
            onSubmit={(event) => {
              event.preventDefault();
              form.transform((data) => ({
                ...data,
                points: Number(toLatinDigits(data.points).replace(/[^\d-]/g, '') || '0'),
              }));
              form.post(`/crm/parties/${partyId}/loyalty`, {
                preserveScroll: true,
                onSuccess: () => {
                  form.reset();
                  setOpen(false);
                },
              });
            }}
          >
            <div className="space-y-2">
              <Label htmlFor="loyalty-points">تعداد امتیاز (منفی برای کسر)</Label>
              <Input
                id="loyalty-points"
                dir="ltr"
                inputMode="numeric"
                className="tabular"
                value={form.data.points}
                onChange={(event) => form.setData('points', event.target.value)}
              />
              {formError(form.errors, 'points') && (
                <p className="text-sm text-danger">{formError(form.errors, 'points')}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="loyalty-reason">دلیل</Label>
              <Input
                id="loyalty-reason"
                value={form.data.description}
                onChange={(event) => form.setData('description', event.target.value)}
              />
              {form.errors.description && (
                <p className="text-sm text-danger">{form.errors.description}</p>
              )}
            </div>

            <div className="flex gap-2">
              <Button type="submit" disabled={form.processing}>
                ثبت
              </Button>
              <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                انصراف
              </Button>
            </div>
          </form>
        ) : (
          <Button type="button" variant="outline" className="mt-4" onClick={() => setOpen(true)}>
            تغییر دستی امتیاز
          </Button>
        ))}
    </SettingsSection>
  );
}
