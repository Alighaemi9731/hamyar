import { Head, useForm } from '@inertiajs/react';
import { ImageOffIcon } from 'lucide-react';
import type { FormEvent } from 'react';

import { FormErrors } from '@/components/domain/form-errors';
import { PageHeader } from '@/components/domain/page-header';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { AppShell } from '@/layouts/app-shell';

interface Props {
  print: {
    logo_url: string | null;
    footer_terms: string | null;
    show_qr: boolean;
  };
  can_update: boolean;
}

/**
 * «چاپ و هویت فروشگاه» — the three things on a receipt that are the shop's and not ours.
 *
 * ## Why this is the screen that mattered most
 *
 * `TenantShopSettings::print()` has been read by the invoice layouts since Phase 5 and
 * **nothing wrote to it**. The logo on a customer's receipt, the warranty wording that
 * gets read back at the shop in an argument, and whether the receipt carries a QR at all
 * could only be changed by editing a JSON column by hand. Everything here lands on paper a
 * shop hands to somebody else, which is why it went first.
 *
 * ## The QR checkbox starts ticked, and that is the stored default rather than a guess
 *
 * The reader treats a missing value as ON — only an explicit `false` turns it off —
 * because a shop that has never opened this screen must not silently lose the feature. The
 * server sends the resolved value, not the raw column, so what is ticked here is exactly
 * what the printer will do.
 *
 * ## The logo preview is the shopkeeper's own test, and it cannot lie
 *
 * It is the same `<img>` the receipt uses, under the same page rules, so an address the
 * receipt cannot load is an address that shows nothing here either. That is worth more
 * than any sentence this screen could write about which addresses work: the shopkeeper
 * pastes, looks, and knows.
 */
export default function PrintSettings({ print, can_update: canUpdate }: Props) {
  const { data, setData, put, processing, errors, isDirty } = useForm({
    logo_url: print.logo_url ?? '',
    footer_terms: print.footer_terms ?? '',
    show_qr: print.show_qr,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();

    put('/settings/print', { preserveScroll: true });
  };

  const logo = data.logo_url.trim();

  return (
    <AppShell
      header={
        <PageHeader
          title="چاپ و هویت فروشگاه"
          description="این سه مورد روی فاکتور فروش، قبض پذیرش و هر برگه‌ای که به دست مشتری می‌رسد چاپ می‌شوند."
          back={{ href: '/settings', label: 'تنظیمات' }}
        />
      }
    >
      <Head title="چاپ و هویت فروشگاه" />

      <form onSubmit={submit} className="max-w-2xl space-y-6">
        {/* The whole bag. A refusal on a key this form did not think to place beside an
            input would otherwise redirect back to an identical screen, and the save button
            would look broken. */}
        <FormErrors errors={errors} />

        {!canUpdate && (
          <p className="rounded-card border border-border bg-surface p-4 text-sm text-muted-foreground">
            شما این تنظیمات را می‌بینید ولی نمی‌توانید تغییرشان دهید. برای ویرایش، از مالک فروشگاه
            بخواهید دسترسی ویرایش تنظیمات فروشگاه را به نقش شما اضافه کند.
          </p>
        )}

        <SettingsSection
          title="لوگوی فروشگاه"
          description="نشانی تصویری که بالای فاکتور چاپ می‌شود. اگر خالی بماند، فقط نام فروشگاه چاپ می‌شود."
        >
          <div className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="logo_url">نشانی لوگو</Label>
              <Input
                id="logo_url"
                // Inherently LTR: it is a web address, read and typed left to right.
                dir="ltr"
                className="ltr-value"
                inputMode="url"
                placeholder="https://example.com/logo.png"
                maxLength={500}
                disabled={!canUpdate}
                aria-invalid={Boolean(errors.logo_url)}
                value={data.logo_url}
                onChange={(event) => setData('logo_url', event.target.value)}
              />
            </div>

            <div className="space-y-2">
              <p className="text-2xs font-medium text-muted-foreground">پیش‌نمایش</p>

              <div className="flex min-h-24 items-center justify-center rounded-control border border-dashed border-border bg-muted/40 p-4">
                {logo === '' ? (
                  <span className="flex items-center gap-2 text-2xs text-muted-foreground">
                    <ImageOffIcon className="size-4" aria-hidden />
                    هنوز لوگویی ثبت نشده است
                  </span>
                ) : (
                  <img
                    src={logo}
                    alt=""
                    aria-hidden
                    className="max-h-16 w-auto object-contain"
                    // A logo that fails to load leaves a gap on a receipt rather than
                    // blocking the print dialog, and the same is true here: no error
                    // handler, because the empty frame is the message.
                  />
                )}
              </div>

              <p className="text-2xs leading-relaxed text-muted-foreground">
                اگر تصویر در این کادر دیده نشد، روی فاکتور هم چاپ نمی‌شود. نشانی دیگری را امتحان
                کنید.
              </p>
            </div>
          </div>
        </SettingsSection>

        <SettingsSection
          title="متن پایین فاکتور"
          description="شرایط گارانتی و مرجوعی فروشگاه شما. همین متن پای فاکتور چاپ می‌شود و همان است که بعداً درباره‌اش گفت‌وگو می‌شود."
        >
          <div className="space-y-1.5">
            <Label htmlFor="footer_terms">متن پایین فاکتور</Label>
            <Textarea
              id="footer_terms"
              rows={4}
              maxLength={1000}
              disabled={!canUpdate}
              aria-invalid={Boolean(errors.footer_terms)}
              value={data.footer_terms}
              onChange={(event) => setData('footer_terms', event.target.value)}
            />
            <p className="text-2xs text-muted-foreground">
              فاکتوری که قبلاً صادر شده با متن همان روز چاپ می‌شود؛ این تغییر روی فاکتورهای بعدی اثر
              دارد.
            </p>
          </div>
        </SettingsSection>

        <SettingsSection
          title="کد QR روی فاکتور"
          description="کدی که مشتری با دوربین گوشی اسکن می‌کند و نسخهٔ آنلاین همان فاکتور را می‌بیند."
        >
          {/* No hue tile beside the box. A hue names a destination — «چاپ و هویت فروشگاه»
              wears amber on the hub — and a second one inside the screen it already named
              would be colour used as decoration. The section heading carries this one. */}
          <Checkbox
            checked={data.show_qr}
            disabled={!canUpdate}
            onCheckedChange={(checked) => setData('show_qr', checked === true)}
            label="کد QR روی فاکتور چاپ شود"
            description="اگر روی نوار باریک چاپ می‌کنید و جا کم دارید، این را بردارید."
          />
        </SettingsSection>

        {canUpdate && (
          <div className="flex items-center gap-3">
            <Button type="submit" disabled={processing}>
              {processing ? 'در حال ذخیره' : 'ذخیره تنظیمات چاپ'}
            </Button>

            {isDirty && !processing && (
              <span className="text-2xs text-muted-foreground">تغییرات هنوز ذخیره نشده است.</span>
            )}
          </div>
        )}
      </form>
    </AppShell>
  );
}
