import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRightIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';

import { JDatePicker } from '@/components/domain/jdate-picker';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { AppShell } from '@/layouts/app-shell';
import { toLatinDigits } from '@/lib/digits';
import { formError } from '@/lib/forms';
import { RIAL_PER_TOMAN } from '@/lib/money';

interface Contact {
  type: string;
  value: string;
  label: string | null;
}

interface PartyData {
  id: number;
  name: string;
  company_name: string | null;
  kind: string;
  national_id: string | null;
  economic_code: string | null;
  price_level_id: number | null;
  credit_limit: number | null;
  opening_balance: number;
  birthday: string | null;
  is_active: boolean;
  notes: string | null;
}

interface Props {
  party: PartyData | null;
  contacts: Contact[];
  kinds: { value: string; label: string }[];
  price_levels: { id: number; label: string }[];
}

const NONE = 'none';

const CONTACT_TYPES = [
  { value: 'mobile', label: 'همراه' },
  { value: 'phone', label: 'تلفن' },
  { value: 'email', label: 'ایمیل' },
];

/**
 * Creating or editing a party.
 *
 * Two fields behave in a way worth knowing at the counter, and the form says so rather
 * than leaving it to be discovered: an empty credit limit means nobody has decided (not
 * zero), and the opening balance is the figure carried in from whatever the shop used
 * before — it is a starting point, never a running total.
 */
export default function PartyEdit({ party, contacts, kinds, price_levels: priceLevels }: Props) {
  const settings = useTenantSettings();
  const toman = settings.currency_display === 'toman';

  const asDisplayed = (rial: number | null): string =>
    rial === null ? '' : String(toman ? rial / RIAL_PER_TOMAN : rial);

  const [rows, setRows] = useState<Contact[]>(
    contacts.length > 0 ? contacts : [{ type: 'mobile', value: '', label: null }]
  );

  const form = useForm({
    name: party?.name ?? '',
    company_name: party?.company_name ?? '',
    kind: party?.kind ?? 'customer',
    national_id: party?.national_id ?? '',
    economic_code: party?.economic_code ?? '',
    price_level_id: String(party?.price_level_id ?? NONE),
    credit_limit: asDisplayed(party?.credit_limit ?? null),
    opening_balance: asDisplayed(party?.opening_balance ?? 0),
    birthday: party?.birthday ?? '',
    is_active: party?.is_active ?? true,
    notes: party?.notes ?? '',
  });

  function submit(event: React.FormEvent): void {
    event.preventDefault();

    const number = (value: string): number | null => {
      const digits = toLatinDigits(value).replace(/[^\d-]/g, '');

      return digits === '' || digits === '-' ? null : Number(digits);
    };

    form.transform((data) => ({
      ...data,
      price_level_id: data.price_level_id === NONE ? null : Number(data.price_level_id),
      // Empty stays null — "nobody decided" is a different fact from a limit of zero.
      credit_limit: number(data.credit_limit),
      opening_balance: number(data.opening_balance) ?? 0,
      national_id: data.national_id === '' ? null : toLatinDigits(data.national_id),
      unit: settings.currency_display,
      contacts: rows.filter((row) => row.value.trim() !== ''),
    }));

    if (party) {
      form.put(`/crm/parties/${party.id}`);
    } else {
      form.post('/crm/parties');
    }
  }

  const title = party ? party.name : 'طرف حساب جدید';

  return (
    <AppShell
      title={title}
      actions={
        <Button variant="outline" asChild>
          <Link href={party ? `/crm/parties/${party.id}` : '/crm'}>
            <ArrowRightIcon className="size-4 rtl:rotate-180" />
            بازگشت
          </Link>
        </Button>
      }
    >
      <Head title={title} />

      <form onSubmit={submit} className="space-y-6">
        <SettingsSection title="مشخصات">
          <div className="grid gap-5 md:grid-cols-2">
            <Field label="نام" htmlFor="party-name" error={form.errors.name}>
              <Input
                id="party-name"
                value={form.data.name}
                autoFocus={!party}
                onChange={(event) => form.setData('name', event.target.value)}
                aria-invalid={Boolean(form.errors.name)}
              />
            </Field>

            <Field
              label="نام شرکت (اختیاری)"
              htmlFor="party-company"
              error={form.errors.company_name}
            >
              <Input
                id="party-company"
                value={form.data.company_name}
                onChange={(event) => form.setData('company_name', event.target.value)}
              />
            </Field>

            <Field
              label="نوع"
              htmlFor="party-kind"
              error={form.errors.kind}
              hint="فقط برای دسته‌بندی است و جلوی هیچ کاری را نمی‌گیرد."
            >
              <Select value={form.data.kind} onValueChange={(value) => form.setData('kind', value)}>
                <SelectTrigger id="party-kind" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent dir="rtl">
                  {kinds.map((kind) => (
                    <SelectItem key={kind.value} value={kind.value}>
                      {kind.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <Field label="سطح قیمت" htmlFor="party-level" error={form.errors.price_level_id}>
              <Select
                value={form.data.price_level_id}
                onValueChange={(value) => form.setData('price_level_id', value)}
              >
                <SelectTrigger id="party-level" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent dir="rtl">
                  <SelectItem value={NONE}>پیش‌فرض فروشگاه</SelectItem>
                  {priceLevels.map((level) => (
                    <SelectItem key={level.id} value={String(level.id)}>
                      {level.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <Field
              label="کد ملی (اختیاری)"
              htmlFor="party-national"
              error={form.errors.national_id}
            >
              <Input
                id="party-national"
                dir="ltr"
                inputMode="numeric"
                className="tabular"
                value={form.data.national_id}
                onChange={(event) => form.setData('national_id', event.target.value)}
                aria-invalid={Boolean(form.errors.national_id)}
              />
            </Field>

            <Field
              label="کد اقتصادی (اختیاری)"
              htmlFor="party-economic"
              error={form.errors.economic_code}
            >
              <Input
                id="party-economic"
                dir="ltr"
                className="tabular"
                value={form.data.economic_code}
                onChange={(event) => form.setData('economic_code', event.target.value)}
              />
            </Field>

            <Field
              label="تاریخ تولد (اختیاری)"
              htmlFor="party-birthday"
              error={form.errors.birthday}
            >
              <JDatePicker
                id="party-birthday"
                value={form.data.birthday}
                onChange={(value) => form.setData('birthday', value ?? '')}
              />
            </Field>
          </div>
        </SettingsSection>

        <SettingsSection
          title="راه‌های تماس"
          description="شماره‌ها با ارقام لاتین ذخیره می‌شوند تا جستجو با هر صفحه‌کلیدی کار کند."
        >
          <div className="space-y-3">
            {rows.map((row, index) => (
              <div key={index} className="grid gap-3 sm:grid-cols-[9rem_1fr_auto] sm:items-end">
                <Select
                  value={row.type}
                  onValueChange={(value) =>
                    setRows(rows.map((item, i) => (i === index ? { ...item, type: value } : item)))
                  }
                >
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent dir="rtl">
                    {CONTACT_TYPES.map((type) => (
                      <SelectItem key={type.value} value={type.value}>
                        {type.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>

                <Input
                  dir="ltr"
                  className="tabular"
                  aria-label="مقدار"
                  value={row.value}
                  onChange={(event) =>
                    setRows(
                      rows.map((item, i) =>
                        i === index ? { ...item, value: event.target.value } : item
                      )
                    )
                  }
                />

                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label="حذف تماس"
                  onClick={() => setRows(rows.filter((_, i) => i !== index))}
                >
                  <Trash2Icon className="size-4 text-destructive" />
                </Button>
              </div>
            ))}
          </div>

          <Button
            type="button"
            variant="outline"
            className="mt-4"
            onClick={() => setRows([...rows, { type: 'mobile', value: '', label: null }])}
          >
            <PlusIcon className="size-4" />
            شماره دیگر
          </Button>
        </SettingsSection>

        <SettingsSection
          title="اعتبار"
          description="مانده اولیه فقط نقطه شروع است — از این به بعد، مانده از روی گردش حساب محاسبه می‌شود."
        >
          <div className="grid gap-5 md:grid-cols-2">
            <Field
              label={`سقف اعتبار (${toman ? 'تومان' : 'ریال'})`}
              htmlFor="party-limit"
              error={formError(form.errors, 'credit_limit')}
              hint="خالی یعنی تصمیمی گرفته نشده. عبور از سقف هشدار می‌دهد، جلوی فروش را نمی‌گیرد."
            >
              <Input
                id="party-limit"
                dir="ltr"
                inputMode="numeric"
                className="tabular"
                value={form.data.credit_limit}
                onChange={(event) => form.setData('credit_limit', event.target.value)}
              />
            </Field>

            <Field
              label={`مانده اولیه (${toman ? 'تومان' : 'ریال'})`}
              htmlFor="party-opening"
              error={formError(form.errors, 'opening_balance')}
              hint="مثبت یعنی از قبل بدهکار است."
            >
              <Input
                id="party-opening"
                dir="ltr"
                inputMode="numeric"
                className="tabular"
                value={form.data.opening_balance}
                onChange={(event) => form.setData('opening_balance', event.target.value)}
              />
            </Field>
          </div>
        </SettingsSection>

        <SettingsSection title="توضیحات">
          <Textarea
            rows={3}
            value={form.data.notes}
            placeholder="مثلاً: همیشه نقد می‌خرد."
            onChange={(event) => form.setData('notes', event.target.value)}
          />

          <Checkbox
            className="mt-4"
            checked={form.data.is_active}
            onCheckedChange={(checked) => form.setData('is_active', checked === true)}
            label="این طرف حساب فعال است"
          />
        </SettingsSection>

        <div className="flex items-center gap-3">
          <Button type="submit" disabled={form.processing}>
            {form.processing ? 'در حال ذخیره…' : party ? 'ذخیره تغییرات' : 'ثبت طرف حساب'}
          </Button>
        </div>
      </form>
    </AppShell>
  );
}

function Field({
  label,
  htmlFor,
  error,
  hint,
  children,
}: {
  label: string;
  htmlFor: string;
  error?: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={htmlFor}>{label}</Label>
      {children}
      {error ? (
        <p className="text-sm text-danger">{error}</p>
      ) : hint ? (
        <p className="text-xs text-muted-foreground">{hint}</p>
      ) : null}
    </div>
  );
}
