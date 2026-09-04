import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CopyIcon, ExternalLinkIcon, LinkIcon } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ConfirmDialog } from '@/components/domain/confirm-dialog';
import { DataTable } from '@/components/domain/data-table';
import { FormErrors } from '@/components/domain/form-errors';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { Card } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { AppShell } from '@/layouts/app-shell';

interface Link {
  id: number;
  label: string | null;
  level: string | null;
  expires_at: string;
  is_expired: boolean;
  is_revoked: boolean;
  has_password: boolean;
  view_count: number;
  last_viewed_at: string;
}

interface Props {
  settings: {
    is_enabled: boolean;
    slug: string | null;
    display_name: string | null;
    about: string | null;
    address: string | null;
    phone: string | null;
    whatsapp: string | null;
    working_hours: string | null;
    shows_out_of_stock: boolean;
  } | null;
  public_url: string | null;
  price_levels: { id: number; name: string }[];
  links: Link[];
  can_manage: boolean;
}

/**
 * The shop's side of the storefront: the public page's settings, and the reseller links.
 *
 * ## The minted token appears once and says so
 *
 * It is stored hashed, so this really is the only moment it exists in readable form. The
 * banner states that plainly rather than leaving a shopkeeper to discover it by navigating
 * away — at which point the only remedy is minting another link.
 */
export default function StorefrontIndex({
  settings,
  public_url: publicUrl,
  price_levels: levels,
  links,
  can_manage: canManage,
}: Props) {
  // The token arrives as a one-shot flash. `SharedProps` types `flash` with the four
  // standard keys, so this one is read off the raw props rather than widening that type
  // for a key only this screen ever sees.
  const { props } = usePage();
  const minted = (props.flash as Record<string, string | null> | undefined)?.minted_link;

  const [revoking, setRevoking] = useState<Link | null>(null);
  const [revokingBusy, setRevokingBusy] = useState(false);
  const [revokeErrors, setRevokeErrors] = useState<Record<string, string>>({});

  return (
    <AppShell
      header={
        <PageHeader
          title="فروشگاه اینترنتی"
          description="یک صفحهٔ عمومی با قیمت‌های مصرف‌کننده، و لینک‌های لیست قیمت همکار که رمز و تاریخ انقضا دارند."
          actions={
            publicUrl ? (
              <Button asChild variant="outline">
                <a href={publicUrl} target="_blank" rel="noopener">
                  <ExternalLinkIcon aria-hidden />
                  دیدن صفحهٔ عمومی
                </a>
              </Button>
            ) : null
          }
        />
      }
    >
      <Head title="فروشگاه اینترنتی" />

      <div className="space-y-8">
        {minted ? <MintedBanner url={minted} /> : null}

        <SettingsForm settings={settings} canManage={canManage} />

        <section className="space-y-4">
          <h2 className="text-lg font-semibold">لینک‌های لیست قیمت همکار</h2>

          {canManage ? <MintForm levels={levels} /> : null}

          {links.length === 0 ? (
            <EmptyState
              icon={LinkIcon}
              title="هنوز لینکی ساخته نشده است"
              description={
                canManage
                  ? 'با فرم بالا یک لینک با سطح قیمت و تاریخ انقضا بسازید و برای همکار بفرستید.'
                  : 'مدیر فروشگاه می‌تواند لینک لیست قیمت همکار بسازد.'
              }
            />
          ) : (
            <>
              <FormErrors errors={revokeErrors} />

              <DataTable
                caption="لینک‌های لیست قیمت همکار، با تاریخ انقضا و شمار بازدید."
                rows={links}
                rowKey={(link) => link.id}
                columns={[
                  { key: 'label', header: 'برچسب', cell: (link) => link.label ?? '—' },
                  { key: 'level', header: 'سطح قیمت', cell: (link) => link.level ?? '—' },
                  {
                    key: 'expires',
                    header: 'انقضا',
                    cell: (link) => (
                      <>
                        {link.expires_at}
                        {/* Said in words, not only in colour. */}
                        {link.is_revoked ? (
                          <span className="ms-2 text-xs text-danger">باطل‌شده</span>
                        ) : link.is_expired ? (
                          <span className="ms-2 text-xs text-warning">منقضی</span>
                        ) : null}
                      </>
                    ),
                  },
                  {
                    key: 'password',
                    header: 'رمز',
                    cell: (link) => (link.has_password ? 'دارد' : 'ندارد'),
                    secondary: true,
                  },
                  {
                    key: 'views',
                    header: 'بازدید',
                    numeric: true,
                    cell: (link) => (
                      <>
                        <Num value={link.view_count} />
                        {link.last_viewed_at ? (
                          <span className="block text-xs text-muted-foreground">
                            آخرین: {link.last_viewed_at}
                          </span>
                        ) : null}
                      </>
                    ),
                    secondary: true,
                  },
                  {
                    key: 'revoke',
                    header: '',
                    cell: (link) =>
                      canManage && !link.is_revoked ? (
                        <Button variant="outline" onClick={() => setRevoking(link)}>
                          ابطال
                        </Button>
                      ) : null,
                  },
                ]}
              />
            </>
          )}
        </section>

        {/*
          Revoking asked nothing before — one click on `router.delete`, on an action the
          banner above describes as unrecoverable: the URL is stored encrypted and cannot be
          read back, so a link revoked by mistake cannot be handed to the same reseller
          again. It also handled no refusal.
        */}
        <ConfirmDialog
          open={revoking !== null}
          onOpenChange={(open) => !open && setRevoking(null)}
          title={revoking ? `ابطال «${revoking.label ?? 'لینک بدون برچسب'}»` : ''}
          description="هرکسی که این نشانی را دارد دیگر نمی‌تواند لیست قیمت را ببیند. نشانی قابل بازیابی نیست؛ برای همان همکار باید لینک تازه بسازید."
          confirmLabel="ابطال لینک"
          processing={revokingBusy}
          onConfirm={() => {
            if (!revoking) return;

            setRevokingBusy(true);
            setRevokeErrors({});

            router.delete(`/storefront/links/${revoking.id}`, {
              preserveScroll: true,
              onError: (received) => setRevokeErrors(received as Record<string, string>),
              onFinish: () => {
                setRevokingBusy(false);
                setRevoking(null);
              },
            });
          }}
        />
      </div>
    </AppShell>
  );
}

function MintedBanner({ url }: { url: string }) {
  const [copied, setCopied] = useState(false);

  return (
    <div className="rounded-card border border-success/25 bg-success/5 p-4">
      <p className="font-semibold">لینک ساخته شد — همین حالا کپی‌اش کنید.</p>
      <p className="mt-1 text-sm text-muted-foreground">
        این نشانی فقط همین یک بار نمایش داده می‌شود. ما آن را رمزنگاری‌شده ذخیره می‌کنیم و دیگر قابل
        بازیابی نیست؛ اگر گمش کردید، لینک تازه بسازید.
      </p>

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <code
          className="min-w-0 flex-1 truncate rounded-control border bg-background p-2 text-xs"
          dir="ltr"
        >
          {url}
        </code>
        <Button
          variant="outline"
          onClick={() => {
            void navigator.clipboard.writeText(url);
            setCopied(true);
          }}
        >
          <CopyIcon className="size-4" aria-hidden />
          {copied ? 'کپی شد' : 'کپی'}
        </Button>
      </div>
    </div>
  );
}

function SettingsForm({
  settings,
  canManage,
}: {
  settings: Props['settings'];
  canManage: boolean;
}) {
  const { data, setData, put, processing, errors } = useForm({
    is_enabled: settings?.is_enabled ?? false,
    slug: settings?.slug ?? '',
    display_name: settings?.display_name ?? '',
    about: settings?.about ?? '',
    address: settings?.address ?? '',
    phone: settings?.phone ?? '',
    whatsapp: settings?.whatsapp ?? '',
    working_hours: settings?.working_hours ?? '',
    shows_out_of_stock: settings?.shows_out_of_stock ?? false,
  });

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        put('/storefront/settings', { preserveScroll: true });
      }}
      className="space-y-4 rounded-card border border-border bg-card p-6"
    >
      <h2 className="text-lg font-semibold">تنظیمات صفحهٔ عمومی</h2>

      {/* Was a hand-rolled copy of this, which also printed `quota` — already rendered by
          the shell through `<QuotaBlock>` with an upgrade button. */}
      <FormErrors errors={errors} />

      <Checkbox
        checked={data.is_enabled}
        onCheckedChange={(value) => setData('is_enabled', value === true)}
        label="صفحهٔ عمومی فعال باشد"
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="نام نمایشی" id="display_name">
          <Input
            id="display_name"
            value={data.display_name}
            onChange={(e) => setData('display_name', e.target.value)}
          />
        </Field>

        <Field label="نشانی کوتاه (slug)" id="slug">
          <Input
            id="slug"
            value={data.slug}
            onChange={(e) => setData('slug', e.target.value)}
            dir="ltr"
          />
        </Field>

        <Field label="تلفن" id="phone">
          <Input
            id="phone"
            value={data.phone}
            onChange={(e) => setData('phone', e.target.value)}
            dir="ltr"
          />
        </Field>

        <Field label="واتس‌اپ" id="whatsapp">
          <Input
            id="whatsapp"
            value={data.whatsapp}
            onChange={(e) => setData('whatsapp', e.target.value)}
            dir="ltr"
          />
        </Field>

        <Field label="ساعت کاری" id="working_hours">
          <Input
            id="working_hours"
            value={data.working_hours}
            onChange={(e) => setData('working_hours', e.target.value)}
          />
        </Field>

        <Field label="آدرس" id="address">
          <Input
            id="address"
            value={data.address}
            onChange={(e) => setData('address', e.target.value)}
          />
        </Field>
      </div>

      <Field label="دربارهٔ فروشگاه" id="about">
        <Textarea
          id="about"
          value={data.about}
          onChange={(e) => setData('about', e.target.value)}
          rows={3}
        />
      </Field>

      <Checkbox
        checked={data.shows_out_of_stock}
        onCheckedChange={(value) => setData('shows_out_of_stock', value === true)}
        label="کالاهای ناموجود هم نمایش داده شوند"
      />

      {canManage ? (
        <Button type="submit" disabled={processing}>
          {processing ? 'در حال ذخیره…' : 'ذخیره'}
        </Button>
      ) : null}
    </form>
  );
}

function MintForm({ levels }: { levels: Props['price_levels'] }) {
  const { data, setData, post, processing, errors } = useForm({
    price_level_id: levels[0]?.id ?? 0,
    label: '',
    password: '',
    days: 7,
  });

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        post('/storefront/links', { preserveScroll: true });
      }}
      className="space-y-4 rounded-card border border-border bg-card p-6"
    >
      {/* Was a hand-rolled copy of this, which also printed `quota` — already rendered by
          the shell through `<QuotaBlock>` with an upgrade button. */}
      <FormErrors errors={errors} />

      <div className="grid gap-4 sm:grid-cols-4">
        <Field label="سطح قیمت" id="price_level_id">
          {/* This was the last hand-written native `<select>` in the tenant app — the
              other was the installments collections screen. A browser's own menu ignores
              the token layer and the RTL work entirely, and looked it in dark mode.
              (Radix still renders a hidden, `aria-hidden` native select for form
              submission; that one is meant to be there.) */}
          <Select
            value={String(data.price_level_id)}
            onValueChange={(value) => setData('price_level_id', Number(value))}
          >
            <SelectTrigger id="price_level_id" className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent dir="rtl">
              {levels.map((level) => (
                <SelectItem key={level.id} value={String(level.id)}>
                  {level.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </Field>

        <Field label="برچسب" id="label">
          <Input id="label" value={data.label} onChange={(e) => setData('label', e.target.value)} />
        </Field>

        <Field label="رمز (اختیاری)" id="password">
          <Input
            id="password"
            value={data.password}
            onChange={(e) => setData('password', e.target.value)}
            dir="ltr"
          />
        </Field>

        <Field label="اعتبار (روز)" id="days">
          <Input
            id="days"
            type="number"
            min={1}
            max={90}
            value={data.days}
            onChange={(e) => setData('days', Number(e.target.value))}
            dir="ltr"
          />
        </Field>
      </div>

      <Button type="submit" disabled={processing}>
        ساخت لینک
      </Button>
    </form>
  );
}

function Field({ label, id, children }: { label: string; id: string; children: React.ReactNode }) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
    </div>
  );
}
