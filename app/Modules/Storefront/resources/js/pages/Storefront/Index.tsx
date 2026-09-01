import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CopyIcon, ExternalLinkIcon } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
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

  return (
    <AppShell title="فروشگاه اینترنتی">
      <Head title="فروشگاه اینترنتی" />

      <div className="space-y-8">
        <header className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold">فروشگاه اینترنتی</h1>
            <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
              یک صفحهٔ عمومی با قیمت‌های مصرف‌کننده، و لینک‌های لیست قیمت همکار که رمز و تاریخ انقضا
              دارند.
            </p>
          </div>

          {publicUrl ? (
            <Button asChild variant="outline">
              <a href={publicUrl} target="_blank" rel="noopener">
                <ExternalLinkIcon className="size-4" aria-hidden />
                دیدن صفحهٔ عمومی
              </a>
            </Button>
          ) : null}
        </header>

        {minted ? <MintedBanner url={minted} /> : null}

        <SettingsForm settings={settings} canManage={canManage} />

        <section className="space-y-4">
          <h2 className="text-lg font-semibold">لینک‌های لیست قیمت همکار</h2>

          {canManage ? <MintForm levels={levels} /> : null}

          {links.length === 0 ? (
            <p className="text-sm text-muted-foreground">هنوز لینکی ساخته نشده است.</p>
          ) : (
            <div className="overflow-x-auto rounded-card border">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-surface-muted text-muted-foreground">
                    <th className="p-3 text-start font-medium">برچسب</th>
                    <th className="p-3 text-start font-medium">سطح قیمت</th>
                    <th className="p-3 text-start font-medium">انقضا</th>
                    <th className="p-3 text-start font-medium">رمز</th>
                    <th className="p-3 text-start font-medium">بازدید</th>
                    <th className="p-3 text-start font-medium" />
                  </tr>
                </thead>
                <tbody>
                  {links.map((link) => (
                    <tr key={link.id} className="border-b last:border-0">
                      <td className="p-3">{link.label ?? '—'}</td>
                      <td className="p-3">{link.level ?? '—'}</td>
                      <td className="p-3 tabular-nums">
                        {link.expires_at}
                        {link.is_revoked ? (
                          <span className="ms-2 text-xs text-danger">باطل‌شده</span>
                        ) : link.is_expired ? (
                          <span className="ms-2 text-xs text-warning">منقضی</span>
                        ) : null}
                      </td>
                      <td className="p-3">{link.has_password ? 'دارد' : 'ندارد'}</td>
                      <td className="p-3 tabular-nums">
                        {link.view_count}
                        {link.last_viewed_at ? (
                          <span className="block text-xs text-muted-foreground">
                            آخرین: {link.last_viewed_at}
                          </span>
                        ) : null}
                      </td>
                      <td className="p-3 text-end">
                        {canManage && !link.is_revoked ? (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                              router.delete(`/storefront/links/${link.id}`, {
                                preserveScroll: true,
                              })
                            }
                          >
                            ابطال
                          </Button>
                        ) : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
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
          size="sm"
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
      className="space-y-4 rounded-card border p-5"
    >
      <h2 className="text-lg font-semibold">تنظیمات صفحهٔ عمومی</h2>

      {Object.keys(errors).length > 0 ? (
        <div
          role="alert"
          className="rounded-control border border-danger/25 bg-danger/5 p-3 text-sm text-danger"
        >
          {Object.values(errors).map((message) => (
            <p key={message}>{message}</p>
          ))}
        </div>
      ) : null}

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
      className="space-y-4 rounded-card border p-5"
    >
      {Object.keys(errors).length > 0 ? (
        <div
          role="alert"
          className="rounded-control border border-danger/25 bg-danger/5 p-3 text-sm text-danger"
        >
          {Object.values(errors).map((message) => (
            <p key={message}>{message}</p>
          ))}
        </div>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-4">
        <Field label="سطح قیمت" id="price_level_id">
          <select
            id="price_level_id"
            value={data.price_level_id}
            onChange={(e) => setData('price_level_id', Number(e.target.value))}
            className="h-10 w-full rounded-control border bg-background px-3 text-sm"
          >
            {levels.map((level) => (
              <option key={level.id} value={level.id}>
                {level.name}
              </option>
            ))}
          </select>
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
